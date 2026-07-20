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
 * expiry + attempt-throttle + resend-cooldown. Anti-enumeration is the caller's
 * responsibility: {@see issue()} is only called for a real target and always
 * returns void, {@see verify()} returns null on every failure without saying why.
 */
class VerificationService
{
    /**
     * Issues a fresh verification code for a (type, identifier), then delivers it.
     *
     * Throttled: while an unexpired, unexhausted code issued within the resend
     * cooldown still exists, the request is silently dropped (no new code, no
     * delivery) so it cannot be used to spam the target. Otherwise any prior
     * active code is voided and a new one is minted and handed to the deliverer.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?int $userId Owning user id when known at issue time, else null
     * @throws EmptyValueException When identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     */
    public function issue(string $type, string $identifier, ?int $userId): void
    {
        if ($identifier === '') {
            throw new EmptyValueException('Verification identifier is required');
        }

        $collection = $this->collection();
        $maxAttempts = $this->maxAttempts();

        $ttlSeconds = $this->ttlSeconds();
        if ($collection->hasRecentActive($type, $identifier, $maxAttempts, $this->resendCooldownSeconds(), $ttlSeconds)) {
            return;
        }

        $collection->voidActive($type, $identifier, $maxAttempts);

        $code = $this->generateSecret($type);
        $collection->createChallenge($type, $identifier, $userId, $code, $ttlSeconds);

        $this->createDeliverer()->deliver($identifier, $type, $code);
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
     * Seam override point: the framework default is the dev-stub
     * {@see LogVerificationDeliverer}; the Notifications leaf (HIL-197) or a
     * project overrides this to return a real email/SMS channel.
     *
     * @return VerificationDeliverer Deliverer for the issued code
     */
    protected function createDeliverer(): VerificationDeliverer
    {
        return new LogVerificationDeliverer();
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
}
