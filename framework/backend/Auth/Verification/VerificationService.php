<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Auth\IdentifierMask;
use Hilos\Auth\MagicLink\MagicLinkUrl;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
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
     * It passes the one send gate of the framework ({@see refuseBySendGate()}, HIL-421),
     * which holds two rules, not one: a cooldown between consecutive sends, and a cap
     * on sends per window. The cooldown alone let a patient script send forever, one
     * code per cooldown; the cap alone let a burst through.
     *
     * Refused either way, nothing is minted and nothing is delivered - the answer
     * says which rule refused, and the caller decides how loudly to say so. Only a
     * send that passes both voids the prior active challenge and mints a new one.
     *
     * Magic-link sign-in mints TWO challenges from this one issue (HIL-606): the link
     * itself, and the companion code that rides in the same letter for a person whose
     * mail and sign-in screen are on different devices. The companion is minted AFTER
     * the gate rather than through it, because one ceremony owes the recipient one
     * letter and one cooldown - a second gate would refuse half a letter, and a resend
     * would extend the halves apart.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?int $userId Owning user id when known at issue time, else null
     * @return VerificationSendOutcome Whether a code went out, and the seconds until the next may
     * @throws EmptyValueException When identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When a send-gate, challenge or magic-link env key is missing,
     *   outside the catalog, or of the wrong type
     * @throws ValidationException When the code was issued for a target the transport refuses
     * @throws InvalidArgumentException When the transport's send signal cannot be named or queued
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function issue(string $type, string $identifier, ?int $userId): VerificationSendOutcome
    {
        if ($identifier === '') {
            throw new EmptyValueException('Verification identifier is required');
        }

        $collection = $this->collection();
        $cooldownSeconds = $this->resendCooldownSeconds();

        $refusal = $this->refuseBySendGate($collection, $type, $identifier, $cooldownSeconds);
        if ($refusal !== null) {
            return $refusal;
        }

        $collection->voidActive($type, $identifier, $this->maxAttempts());

        $secret = $this->generateSecret($type);
        $collection->createChallenge($type, $identifier, $userId, $secret, $this->ttlSeconds());

        $companionCode = $type === VerificationType::MAGIC_LINK
            ? $this->mintMagicLinkCode($collection, $identifier, $userId)
            : null;

        $this->createDeliverer()->deliver(
            $identifier,
            $type,
            $this->deliverableFor($identifier, $secret, $companionCode),
        );

        return VerificationSendOutcome::sent($cooldownSeconds);
    }

    /**
     * Issues a code for a (type, identifier) over a named channel WITHOUT delivering it (HIL-492).
     *
     * The half of {@see issue()} a code channel needs. Delivery there is one step and
     * belongs to the service; here it is two - the channel has already proven the
     * target reachable over the network and will send the code itself, over a
     * transport this service knows nothing about - so the mint has to hand the code
     * back instead of consuming it. Everything the send gate protects is unchanged:
     * the same cooldown and the same per-window cap, counted on the same
     * (type, identifier) key.
     *
     * The key deliberately excludes the channel. Counting per channel would let a
     * stranger walk the registry and buy one code per channel from a single number's
     * budget, so the limit is on the target - which is what costs money and what a
     * person's phone actually receives - and picking a different channel is not a way
     * around it.
     *
     * The channel is recorded on the challenge rather than merely obeyed, because a
     * resend has to repeat the channel the person chose and the click that chose it is
     * long gone by then.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (E.164 phone for the code channels)
     * @param ?int $userId Owning user id when known at issue time, else null
     * @param string $channel Code channel the caller will deliver over (see CodeChannel::name())
     * @return VerificationIssuedCode The gate's verdict, and the plaintext code when one was minted
     * @throws EmptyValueException When identifier or channel is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When a send-gate or challenge env key is missing, outside the catalog,
     *   or of the wrong type
     * @throws InvalidArgumentException When a verification query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function issueForChannel(
        string $type,
        string $identifier,
        ?int $userId,
        string $channel,
    ): VerificationIssuedCode {
        if ($identifier === '') {
            throw new EmptyValueException('Verification identifier is required');
        }
        if ($channel === '') {
            throw new EmptyValueException('Verification channel is required');
        }

        $collection = $this->collection();
        $cooldownSeconds = $this->resendCooldownSeconds();

        $refusal = $this->refuseBySendGate($collection, $type, $identifier, $cooldownSeconds);
        if ($refusal !== null) {
            return VerificationIssuedCode::refused($refusal);
        }

        $collection->voidActive($type, $identifier, $this->maxAttempts());

        $code = $this->generateCode();
        $collection->createChallenge($type, $identifier, $userId, $code, $this->ttlSeconds(), $channel);

        return VerificationIssuedCode::minted(VerificationSendOutcome::sent($cooldownSeconds), $code);
    }

    /**
     * The channel the live challenge of a (type, identifier) was delivered over (HIL-492).
     *
     * What a resend reads to repeat the channel the person chose: the click that chose
     * it belongs to a screen ago, and asking again on the code screen would be asking
     * the same question twice. Null when nothing is live, or when the live challenge
     * carries no channel - every flow that never offered a choice mints one that way,
     * and a caller reads that as "the type's own rule", not as an error.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (E.164 phone for the code channels)
     * @return ?string Channel name of the live challenge, or null when there is none to repeat
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function activeChannel(string $type, string $identifier): ?string
    {
        return $this->collection()->findActive($type, $identifier, $this->maxAttempts())?->channel;
    }

    /**
     * When the live challenge of a (type, identifier) stops being good (HIL-486).
     *
     * What a waiting screen counts down: the code or link the person is holding dies
     * at a moment, and only the challenge itself knows which one - a send that the
     * cooldown swallowed left the PREVIOUS code in play, and telling the screen "a
     * full lifetime from now" would have it count down to a code that died sooner.
     *
     * Answered in milliseconds because it is going to a browser, which is told every
     * moment on the same scale (Flow p.7); the row keeps SQL datetimes, and this is
     * the one place that conversion belongs for this fact.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email, E.164 phone)
     * @return ?int Epoch milliseconds the live challenge expires at, or null when nothing is live
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function activeExpiresAt(string $type, string $identifier): ?int
    {
        $expiresAt = $this->collection()->findActive($type, $identifier, $this->maxAttempts())?->expiresAt;

        return $expiresAt === null ? null : TimeHelper::sqlToMs($expiresAt);
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
     * @throws EnvException When a send-gate env key is missing, outside the catalog, or
     *   not an int
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
     * The moment the cooldown lets a re-send through, in epoch milliseconds (HIL-486).
     *
     * The browser-facing form of {@see resendAllowedInSeconds()}, and the reason it
     * exists rather than each caller adding "now" itself: a duration handed to a tab
     * is wrong the instant that tab is reloaded, because nobody wrote down when the
     * counting started. The sibling conversion for a send that just happened lives on
     * {@see VerificationSendOutcome::resendAt()}; this one answers the sites that ask
     * about a cooldown they did not just start.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @return int Epoch milliseconds a re-send is allowed at (now, when nothing blocks one)
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When a send-gate env key is missing, outside the catalog, or
     *   not an int
     */
    public function resendAllowedAt(string $type, string $identifier): int
    {
        return TimeHelper::nowMs()
            + $this->resendAllowedInSeconds($type, $identifier) * TimeConstants::MS_PER_SECOND;
    }

    /**
     * Verifies a submitted code and returns the resolved user id on success.
     *
     * Loads the single active challenge, records the attempt, and compares the
     * code in constant time. A wrong code that reaches the attempt ceiling voids
     * the challenge; a correct code consumes it (single-use). Every failure —
     * no active challenge, wrong code, exhausted attempts, a code that matched but
     * was spent by a parallel worker first (HIL-679) — returns null with no
     * distinguishing signal.
     *
     * The attempt is recorded first, and the row decides whether there was budget
     * for it (HIL-715): an attempt the ceiling refuses voids the challenge and never
     * reaches the comparison, so a worker whose own count was behind the row's spends
     * no bcrypt on a guess the budget had already run out for.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $code Submitted plaintext code
     * @return ?int Resolved user id on success, null on any failure
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function verify(string $type, string $identifier, string $code): ?int
    {
        $collection = $this->collection();
        $maxAttempts = $this->maxAttempts();

        $challenge = $collection->findActive($type, $identifier, $maxAttempts);
        if ($challenge === null) {
            return null;
        }

        if (!$challenge->incrementAttempts($maxAttempts)) {
            $challenge->consume();

            return null;
        }

        if (!$challenge->verifyCode($code)) {
            if ($challenge->attempts >= $maxAttempts) {
                $challenge->consume();
            }

            return null;
        }

        if (!$challenge->consume()) {
            return null;
        }

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
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function verifyCode(string $type, string $identifier, string $code): bool
    {
        return $this->consumeIfMatches($type, $identifier, $code) !== null;
    }

    /**
     * Whether a (type, identifier) still has a challenge that could be answered (HIL-416).
     *
     * A pure read, and the one question a submitted code cannot answer: a wrong code and
     * a challenge that is no longer there both fail, and the person is owed different
     * things - one is a typo to correct on the spot, the other is a flow to start again.
     * Every flow that rolls its surface back rather than rejecting a code asks this
     * first; nothing is written and no attempt is counted.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @return bool True when a live challenge is waiting for a code
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function hasActive(string $type, string $identifier): bool
    {
        return $this->collection()->findActive($type, $identifier, $this->maxAttempts()) !== null;
    }

    /**
     * Checks a submitted code against the live challenge WITHOUT spending it (HIL-416).
     *
     * The half of {@see verifyCode()} that recovery needs: password reset accepts the
     * code on one screen and saves the new password on the next, so the code has to
     * survive the step in between - it is what the grant to save is made of. Spending
     * it at the code screen would mean either holding the reset open with no proof
     * behind it, or asking for the code twice.
     *
     * Everything the ceiling protects is kept: a wrong code costs an attempt, and one
     * that reaches the ceiling voids the challenge exactly as it does on the consuming
     * path - so this is not a way to guess a code without cost. The spend moves to
     * {@see consumeActive()}, which the saving step calls.
     *
     * Only a WRONG code is counted, which is where this parts company with
     * {@see verify()}. There the attempt is recorded first and a match consumes the
     * challenge, so what the counter then reads never matters. Here the challenge has
     * to survive the match: counting a correct code would let the right answer, given
     * on the last permitted attempt, put the challenge over the ceiling and leave the
     * person holding a password step with nothing left to spend.
     *
     * That order has a deliberate consequence now that the row and not the worker's
     * mirror holds the budget (HIL-715): a worker whose mirror is behind will accept a
     * CORRECT code against a row that is already exhausted. It is the right trade —
     * the ceiling exists to bound guessing, and knowing the code is not guessing.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $code Submitted plaintext code
     * @return bool True when the code matched the live challenge, false on any failure
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function matchCode(string $type, string $identifier, string $code): bool
    {
        $collection = $this->collection();
        $maxAttempts = $this->maxAttempts();

        $challenge = $collection->findActive($type, $identifier, $maxAttempts);
        if ($challenge === null) {
            return false;
        }

        if ($challenge->verifyCode($code)) {
            return true;
        }

        if (!$challenge->incrementAttempts($maxAttempts)) {
            $challenge->consume();

            return false;
        }

        if ($challenge->attempts >= $maxAttempts) {
            $challenge->consume();
        }

        return false;
    }

    /**
     * Spends the live challenge of a (type, identifier) without asking for the code.
     *
     * The other half of the split {@see matchCode()} opens (HIL-416): the code was
     * already proven a step earlier and is not on the wire any more, so what the
     * saving step has to do is burn it. Answering whether there WAS one to burn is
     * the point of the return - the challenge is the recovery's single-use ticket,
     * so an absent one means this session is too late (another one finished the
     * reset, or the code died waiting), and false is that fact rather than a
     * failure to work.
     *
     * Two sessions that read the same live challenge get that fact from the burn
     * itself rather than from the lookup (HIL-679): the loser is told false having
     * seen a challenge, which is the same "too late" as having seen none, and the
     * caller above it takes the same branch either way.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @return bool True when this call spent a live challenge, false when none was left to spend
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function consumeActive(string $type, string $identifier): bool
    {
        $challenge = $this->collection()->findActive($type, $identifier, $this->maxAttempts());
        if ($challenge === null) {
            return false;
        }

        return $challenge->consume();
    }

    /**
     * The send gate itself: answers the refusal that stops a send, or null to let it through.
     *
     * Extracted so the two issue paths cannot drift (HIL-492): {@see issue()} delivers
     * the code itself and {@see issueForChannel()} hands it to a channel, but "may this
     * target be sent to right now" is one rule and has to stay one piece of code -
     * otherwise a change to the cooldown or the cap would tighten one door and leave
     * the other open, which is a hole that looks like working software.
     *
     * Both rules count the ISSUE, not the delivery, so a challenge the target already
     * threw away and a transport that failed afterwards cannot buy another send.
     *
     * Neither rule is atomic: the count is read here and the row is written by the
     * issue path in a separate statement, so a burst on one identifier passes the cap
     * (docs/agents/architecture/verification-codes.md, which also says why it is left
     * that way and what the answer would be).
     *
     * @param ObjectUserVerifications $collection Verifications persistence primitives
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier
     * @param int $cooldownSeconds Configured minimum seconds between issues for one target
     * @return ?VerificationSendOutcome Refusal that stops the send, or null when it may proceed
     * @throws DatabaseException When a verification query fails
     * @throws EnvException When a send-gate env key is missing, outside the catalog, or not an int
     * @throws InvalidArgumentException When a verification query is given an invalid order direction
     */
    private function refuseBySendGate(
        ObjectUserVerifications $collection,
        string $type,
        string $identifier,
        int $cooldownSeconds,
    ): ?VerificationSendOutcome {
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

        return null;
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
     * Every outcome is written to the log exactly once, here rather than in the
     * flows above (HIL-607). This is the one place all of them pass through, so a
     * line written here covers both halves of the magic-link letter and the
     * registration and recovery codes alike, and cannot be forgotten by the next
     * flow to be added. The silence toward the PERSON is unchanged — none of this
     * reaches the wire.
     *
     * This is also the only place a lost race is written down
     * ({@see VerificationRejectReason::RACE_LOST}, HIL-679): a matching code that
     * another worker spent first is refused like any other, but it is the one
     * refusal that says nothing about the submitter, so an operator seeing them
     * accumulate should look at the front end submitting twice.
     *
     * An attempt the row itself refuses (HIL-715) is logged as the exhaustion it is,
     * with the count read back from the row rather than from this worker's mirror.
     * The set of reasons does not grow for it: what changed is which of them notices —
     * the write's condition rather than a number the worker remembered.
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
            $this->logRejected(
                $type,
                $identifier,
                $collection->describeInactive($type, $identifier, $maxAttempts),
                0,
                $maxAttempts,
                null,
            );

            return null;
        }

        if (!$challenge->incrementAttempts($maxAttempts)) {
            $challenge->consume();
            $this->logRejected(
                $type,
                $identifier,
                VerificationRejectReason::ATTEMPTS_EXHAUSTED,
                $challenge->attempts,
                $maxAttempts,
                $challenge->id,
            );

            return null;
        }

        if (!$challenge->verifyCode($code)) {
            $exhausted = $challenge->attempts >= $maxAttempts;
            if ($exhausted) {
                $challenge->consume();
            }
            $this->logRejected(
                $type,
                $identifier,
                $exhausted
                    ? VerificationRejectReason::ATTEMPTS_EXHAUSTED
                    : VerificationRejectReason::SECRET_MISMATCH,
                $challenge->attempts,
                $maxAttempts,
                $challenge->id,
            );

            return null;
        }

        if (!$challenge->consume()) {
            $this->logRejected(
                $type,
                $identifier,
                VerificationRejectReason::RACE_LOST,
                $challenge->attempts,
                $maxAttempts,
                $challenge->id,
            );

            return null;
        }

        Logger::info(
            'verification consume accepted: type=' . $type
                . ' identifier=' . IdentifierMask::mask($identifier)
                . ' attempts=' . $challenge->attempts . '/' . $maxAttempts
                . ' id=' . (string)$challenge->id,
        );

        return $challenge;
    }

    /**
     * Writes the one line a refused consume leaves behind.
     *
     * The refusal is the half that used to leave nothing at all: a magic-link click
     * that failed produced no row, no signal and no log entry, so the only way to
     * tell a click that never arrived from one the backend turned down was to read
     * the table by hand (HIL-607). Warning rather than error, because a stale link
     * is an ordinary thing a person does, not a fault of the system.
     *
     * The identifier is masked and the submitted secret is not named at all — not
     * the code, not the token, not its hash. What is written is what an operator
     * can act on: which flow, roughly whose, why, how much budget was left, and the
     * row to look at.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier, masked before it is written
     * @param string $reason Why the consume was refused (see VerificationRejectReason)
     * @param int $attempts Attempts recorded against the challenge, or 0 when there was none
     * @param int $maxAttempts Configured attempt ceiling
     * @param ?int $verificationId Challenge row the refusal is about, or null when none was found
     */
    private function logRejected(
        string $type,
        string $identifier,
        string $reason,
        int $attempts,
        int $maxAttempts,
        ?int $verificationId,
    ): void {
        Logger::warning(
            'verification consume rejected: type=' . $type
                . ' identifier=' . IdentifierMask::mask($identifier)
                . ' reason=' . $reason
                . ' attempts=' . $attempts . '/' . $maxAttempts
                . ' id=' . ($verificationId === null ? '-' : (string)$verificationId),
        );
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
     * What the deliverer is handed: a clickable URL plus its companion code for the
     * magic link, the bare code for everything else (HIL-417, HIL-606).
     *
     * The link is assembled here, one level above the transport, because there are
     * two deliverers and both owe the recipient the same address - a URL built inside
     * the mail deliverer would leave the dev-stub log with a token nobody can click.
     * What is STORED does not change: each challenge still carries the hash of its own
     * bare secret, so verification is unaffected by how either half travelled.
     *
     * The companion code is what says WHICH shape is being built: only a magic-link
     * issue mints one, so its presence carries the branch the type used to carry.
     * Asking it rather than re-testing the type keeps the letter and the params
     * describing the same mint - a type-keyed branch could name a link this issue
     * never assembled.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $secret Freshly minted token or code
     * @param ?string $companionCode Magic-link companion code, or null for every other type
     * @return VerificationDeliverable What the transport delivers: a lone code, or link plus code
     * @throws EnvException When the magic-link return address is missing, outside the
     *   catalog, or not a string
     */
    private function deliverableFor(
        string $identifier,
        string $secret,
        ?string $companionCode,
    ): VerificationDeliverable {
        if ($companionCode === null) {
            return VerificationDeliverable::code($secret);
        }

        return VerificationDeliverable::magicLink(
            MagicLinkUrl::build(
                Hilos::$env->string(EnvConstants::HILOS_MAGIC_LINK_URL),
                $identifier,
                $secret,
            ),
            $companionCode,
        );
    }

    /**
     * Mints the companion code that rides in the magic-link letter (HIL-606).
     *
     * A challenge of its own ({@see VerificationType::MAGIC_LINK_CODE}) rather than a
     * second column on the link's row, for two reasons that both cost data if ignored:
     * the two secrets carry two attempt ceilings, so guessing the six digits must not
     * spend the link's budget, and every lookup in this service keys on the type, so a
     * second secret sharing a row could not be found, voided or consumed on its own.
     *
     * The prior companion is voided first, exactly as the link's own mint does, so a
     * resend leaves one live code rather than a growing set. The owning user is copied
     * from the link's issue: both halves prove the same address.
     *
     * @param ObjectUserVerifications $collection Verifications persistence primitives
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?int $userId Owning user id when known at issue time, else null
     * @return string Plaintext companion code to put in the letter
     * @throws EmptyValueException When identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws DatabaseException When a verification query fails
     * @throws EnvException When a challenge env key is missing, outside the catalog,
     *   or of the wrong type
     * @throws InvalidArgumentException When a verification query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    private function mintMagicLinkCode(
        ObjectUserVerifications $collection,
        string $identifier,
        ?int $userId,
    ): string {
        $collection->voidActive(VerificationType::MAGIC_LINK_CODE, $identifier, $this->maxAttempts());

        $code = $this->generateCode();
        $collection->createChallenge(
            VerificationType::MAGIC_LINK_CODE,
            $identifier,
            $userId,
            $code,
            $this->ttlSeconds(),
        );

        return $code;
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
