<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Random\RandomException;

/**
 * VerificationService - issues and verifies user verification codes (HIL-365).
 *
 * The single mechanism behind email-confirmation and password-recovery: a typed
 * challenge is issued (throttled, expiring, delivered through the seam) and
 * later verified (constant-time, attempt-limited, single-use). It owns only the
 * generic mechanism — deciding which identity to touch on success (flip
 * `verified`, set a new password) is the calling flow's job, so the service
 * stays agnostic to the flow.
 *
 * Security posture (do-better vs the copy-pasted reference): cryptographic
 * {@see random_int()} codes; bcrypt-hashed at rest with constant-time compare
 * (the code hash never leaves the object layer, mirroring `identity.secret`);
 * expiry + attempt-throttle + send gate (cooldown and per-window cap, HIL-421).
 * Anti-enumeration is the caller's responsibility: {@see issue()} answers the same
 * outcome shape whether or not the target exists, and {@see verify()} returns null
 * on every failure without saying why.
 */
class VerificationService
{
    /**
     * Issues a fresh verification code for a (type, identifier), then delivers it.
     *
     * The one send gate of the framework (HIL-421), and it holds two rules, not
     * one: a cooldown between consecutive sends, and a cap on sends per window.
     * The cooldown alone let a patient script send forever, one code per cooldown;
     * the cap alone let a burst through. Both count the ISSUE, so a challenge the
     * target already threw away and a transport that failed afterwards cannot buy
     * another send.
     *
     * Refused either way, nothing is minted and nothing is delivered - the answer
     * says which rule refused, and the caller decides how loudly to say so. Only a
     * send that passes both voids the prior active challenge and mints a new one.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?int $userId Owning user id when known at issue time, else null
     * @return VerificationSendOutcome Whether a code went out, and the seconds until the next may
     * @throws EmptyValueException When identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     */
    public function issue(string $type, string $identifier, ?int $userId): VerificationSendOutcome
    {
        if ($identifier === '') {
            throw new EmptyValueException('Verification identifier is required');
        }

        $collection = $this->collection();
        $cooldownSeconds = $this->resendCooldownSeconds();
        $stats = $collection->sendStats($type, $identifier, $this->sendWindowSeconds());

        if ($stats->lastIssuedAt !== null) {
            $heldForSeconds = $stats->lastIssuedAt + $cooldownSeconds - time();
            if ($heldForSeconds > 0) {
                return VerificationSendOutcome::heldByCooldown($heldForSeconds);
            }
        }

        if ($stats->sentInWindow >= $this->sendCapFor($type)) {
            return VerificationSendOutcome::capReached();
        }

        $collection->voidActive($type, $identifier, $this->maxAttempts());

        $code = $this->generateSecret($type);
        $collection->createChallenge($type, $identifier, $userId, $code, $this->ttlSeconds());

        $this->createDeliverer()->deliver($identifier, $type, $code);

        return VerificationSendOutcome::sent($cooldownSeconds);
    }

    /**
     * How long the cooldown still blocks a re-send for a (type, identifier).
     *
     * The public read of the rule {@see issue()} applies silently, opened by the
     * reserve-on-submit surface (HIL-415): the code screen draws a countdown before
     * it offers a resend button, and the only honest source for that number is the
     * rule the resend itself obeys. Without it the frontend would guess a cooldown,
     * and a guess that runs short turns a silently dropped issue into a button that
     * appears to do nothing.
     *
     * Counted from the last issue, so a target whose challenge already died still
     * waits out the cooldown - which is the point, since it is the delivered message
     * that is being rationed and not the code. Answers 0 when nothing blocks a
     * re-send. The cap is deliberately not folded in: it refuses out loud rather
     * than with a number, and a countdown here would promise a button that is not
     * coming back.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @return int Seconds until a re-send is allowed, or 0 when it is allowed now
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     */
    public function resendAllowedInSeconds(string $type, string $identifier): int
    {
        $stats = $this->collection()->sendStats($type, $identifier, $this->sendWindowSeconds());
        if ($stats->lastIssuedAt === null) {
            return 0;
        }

        return max(0, $stats->lastIssuedAt + $this->resendCooldownSeconds() - time());
    }

    /**
     * Verifies a submitted code and returns the resolved user id on success.
     *
     * Loads the single active challenge, records the attempt, and compares the
     * code in constant time. A wrong code that reaches the attempt ceiling voids
     * the challenge; a correct code consumes it (single-use). Every failure —
     * no active challenge, wrong code, exhausted attempts — returns null with no
     * distinguishing signal.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $code Submitted plaintext code
     * @return ?int Resolved user id on success, null on any failure
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     */
    public function verify(string $type, string $identifier, string $code): ?int
    {
        $collection = $this->collection();
        $maxAttempts = $this->maxAttempts();

        $challenge = $collection->findActive($type, $identifier, $maxAttempts);
        if ($challenge === null) {
            return null;
        }

        $challenge->incrementAttempts();

        if (!$challenge->verifyCode($code)) {
            if ($challenge->attempts >= $maxAttempts) {
                $challenge->consume();
            }

            return null;
        }

        $challenge->consume();

        return $challenge->userId;
    }

    /**
     * Verifies a submitted code without resolving an owning user (HIL-280).
     *
     * The sibling of {@see verify()} for flows whose challenge carries no
     * `user_id` — SMS login issues its code before any user is known
     * ({@see VerificationType::SMS_LOGIN}), so a resolved-user return is
     * meaningless and null could not distinguish success from failure. This
     * answers the yes/no the caller actually needs; the find-or-create of the
     * phone user is the calling flow's job.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (E.164 phone for SMS login)
     * @param string $code Submitted plaintext code
     * @return bool True when the code matched and was consumed, false on any failure
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     */
    public function verifyCode(string $type, string $identifier, string $code): bool
    {
        return $this->consumeIfMatches($type, $identifier, $code) !== null;
    }

    /**
     * Shared verify core: loads the single active challenge, records the attempt,
     * compares the code in constant time, and single-use consumes it on a match.
     *
     * The generic primitive behind {@see verifyCode()} (and the shape {@see verify()}
     * mirrors): a wrong code that reaches the attempt ceiling voids the challenge; a
     * correct code consumes it; every failure returns null with no distinguishing
     * signal. It returns the consumed challenge so a caller can read its `userId`
     * when the flow needs it.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier
     * @param string $code Submitted plaintext code
     * @return ?ObjectUserVerification Consumed challenge on success, null on any failure
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     */
    private function consumeIfMatches(string $type, string $identifier, string $code): ?ObjectUserVerification
    {
        $collection = $this->collection();
        $maxAttempts = $this->maxAttempts();

        $challenge = $collection->findActive($type, $identifier, $maxAttempts);
        if ($challenge === null) {
            return null;
        }

        $challenge->incrementAttempts();

        if (!$challenge->verifyCode($code)) {
            if ($challenge->attempts >= $maxAttempts) {
                $challenge->consume();
            }

            return null;
        }

        $challenge->consume();

        return $challenge;
    }

    /**
     * Builds the deliverer used to hand off a freshly issued code.
     *
     * Seam override point: the framework default is {@see NotificationVerificationDeliverer},
     * which routes each type to its channel - email types through {@see Hilos::$mail}
     * (HIL-197), the SMS types through {@see Hilos::$sms} (HIL-285). The dev-stub
     * {@see LogVerificationDeliverer} stays in the tree for tests; a project overrides this to
     * add or swap a channel.
     *
     * @return VerificationDeliverer Deliverer for the issued code
     */
    protected function createDeliverer(): VerificationDeliverer
    {
        return new NotificationVerificationDeliverer();
    }

    /**
     * Generates the challenge secret for a type: a long URL-safe token for the
     * link-delivered types, a short numeric code for the rest.
     *
     * Magic-link sign-in (HIL-283) is delivered as a clickable URL, not typed by a
     * human, so its secret is a high-entropy `bin2hex(random_bytes(32))` token
     * rather than a short numeric code — a 6-digit code that travels in a URL and
     * verifies with the same attempt ceiling would be brute-forceable. Every other
     * type stays a numeric code the recipient reads and types. Either way the value
     * is hashed at rest by {@see ObjectUserVerifications::createChallenge()} and
     * compared in constant time by {@see verify()}, so the storage/verify path is
     * identical.
     *
     * @param string $type Verification type (see VerificationType)
     * @return string The generated token or numeric code
     * @throws RandomException When the platform CSPRNG cannot produce a value
     */
    private function generateSecret(string $type): string
    {
        if ($type === VerificationType::MAGIC_LINK) {
            return bin2hex(random_bytes(32));
        }

        return $this->generateCode();
    }

    /**
     * Generates a zero-padded numeric verification code.
     *
     * @return string Numeric code of the configured length
     * @throws RandomException When the platform CSPRNG cannot produce a value
     */
    private function generateCode(): string
    {
        $length = $this->codeLength();
        $max = (10 ** $length) - 1;

        return str_pad((string)random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Resolves the framework-owned verifications object collection.
     *
     * @return ObjectUserVerifications Verifications persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     */
    private function collection(): ObjectUserVerifications
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::verifications);
        if (!$collection instanceof ObjectUserVerifications) {
            throw new LogicException('Verifications object collection is not configured');
        }

        return $collection;
    }

    /**
     * @return int Configured code length (digits)
     */
    private function codeLength(): int
    {
        return max(1, Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_CODE_LENGTH));
    }

    /**
     * @return int Configured code time-to-live in seconds
     */
    private function ttlSeconds(): int
    {
        return Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_TTL_SEC);
    }

    /**
     * @return int Configured maximum verify attempts per code
     */
    private function maxAttempts(): int
    {
        return max(1, Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS));
    }

    /**
     * @return int Configured minimum seconds between issued codes for one target
     */
    private function resendCooldownSeconds(): int
    {
        return Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC);
    }

    /**
     * @return int Configured length in seconds of the window issued codes are counted in
     */
    private function sendWindowSeconds(): int
    {
        return Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_SEND_WINDOW_SEC);
    }

    /**
     * Configured cap on codes one target may be sent per window, per channel.
     *
     * @param string $type Verification type (see VerificationType)
     * @return int Codes allowed inside one window for this type
     */
    private function sendCapFor(string $type): int
    {
        if (VerificationType::isSms($type)) {
            return Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_SEND_CAP_SMS);
        }

        return Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_SEND_CAP);
    }
}
