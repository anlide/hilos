<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\AuthenticatorName;
use Hilos\Core\Exception\ValidationException;
use Hilos\Users\AddressablePerson;
use Hilos\Users\DTO\SecondFactorEnrollmentData;
use Hilos\Users\DTO\UserSecondFactorResetRequestSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRequestDoneSignalData;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;
use Throwable;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Database\Database;
use Hilos\Database\View\Item\SecondFactor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSecondFactorCodesIssueDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorCodesIssueSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollStartDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollStartSignalData;
use Hilos\Users\DTO\UserSecondFactorProveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorProveSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockSignalData;
use Hilos\Users\DTO\UserSecondFactorWaitWriteDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorWaitWriteSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use JsonException;

/**
 * The edits of one person's second factor, written by that person's agent (HIL-1406).
 *
 * {@see AbstractUserAgent} hands each frame of {@see AbstractUsersLibraryAgent} here and sends the
 * answer back. Everything written is the person's own set: an app's step taken and the app
 * confirmed or removed, a backup code burned, the wrong app codes counted and the lock they put or
 * an operator lifted, the removal wait chosen, a removal canceled, carried out or marked reminded,
 * and - when the factor goes whole - the trust of every browser that skipped it (HIL-1407).
 * Creation and replacement run here too: the new enrollment, backup codes and delayed reset
 * commit before their material is returned to the requesting library.
 *
 * A check that reads the set is made here, in the turn that writes: the code is the write itself,
 * and whether an app is the person's and the last one is asked where the app is deleted, so two
 * tabs cannot both see "not the last". A refusal on the merits is the answer, in the words
 * {@see SecondFactorMessages} has always used; a failure of the storage leaves as an exception, and
 * the agent turns it into the answer.
 *
 * Every app code but the first one of a new app is held to one ceiling per person (HIL-1285):
 * a wrong one is counted on the person's settings row - which this agent creates on that first
 * miss, as it does on the first choice of a wait - and too many in a day lock app codes for a step
 * of a ladder, refused before they are checked. Backup codes stay outside the lock and the count.
 */
final class SecondFactorPersonEdits
{
    /** Longest name an authenticator may have, as the column holds it. */
    private const int LABEL_MAX = 64;

    /** Bytes of a reset cancel token. */
    private const int TOKEN_BYTES = 32;

    /**
     * @param int $userId Person whose set is written, the index of the agent
     */
    public function __construct(private readonly int $userId)
    {
    }

    /**
     * Checks a code that proves the person, and on the code step of a sign-in ends the removal that stands.
     *
     * Whoever shows the factor has not lost it, which is why a right code on the sign-in step cancels
     * a standing removal; the profile and the confirmation of an operation leave it.
     * A merge reservation keeps the removal standing while pre-fact proof still spends and counts normally.
     * Profile enrollment and code renewal refuse a reservation before consuming their proof.
     *
     * @param UserSecondFactorProveSignalData $ask The code and where it was typed
     * @return UserSecondFactorProveDoneSignalData Whether it proved the person, what it counted, and what it canceled
     * @throws ValidationException When a merge holds a profile enrollment or code renewal
     * @throws HilosException When a lookup, a write or the env read fails
     * @throws JsonException When a saved merge result cannot be decoded
     */
    public function prove(UserSecondFactorProveSignalData $ask): UserSecondFactorProveDoneSignalData
    {
        AccountMergeAccess::requireSignIn($this->userId);
        if (in_array($ask->action, [
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW,
        ], true)) {
            AccountMergeAccess::requireProtectionChange($this->userId);
        }
        $check = $this->check($ask->code, $ask->backupCode);
        $resetCanceled = $check->proven
            && $ask->cancelReset
            && AccountMergeAccess::reservation($this->userId) === null
            && Hilos::$db->secondFactorResets->liveOf($this->userId)?->actions->cancel() === true;

        return new UserSecondFactorProveDoneSignalData(
            $ask,
            $check->missed,
            $check->lockMisses,
            $check->lockUntil,
            $resetCanceled,
            $check->refusal,
            null,
            null,
        );
    }

    /**
     * Confirms the person's unfinished enrolment with its first code.
     *
     * The enrolment has to stand, be younger than a verification lives, and be the one the profile
     * form names. The first code of a new app is outside the ceiling: a miss is not counted.
     *
     * @param UserSecondFactorEnrollConfirmSignalData $ask The enrolment, its first code and its name
     * @return UserSecondFactorEnrollConfirmDoneSignalData Whether the app is confirmed, and whether it is the first
     * @throws HilosException When a lookup, a write or the env read fails
     * @throws JsonException When a saved merge result cannot be decoded
     */
    public function enrollConfirm(UserSecondFactorEnrollConfirmSignalData $ask): UserSecondFactorEnrollConfirmDoneSignalData
    {
        AccountMergeAccess::requireProtectionChange($this->userId);
        $pending = Hilos::$db->secondFactors->unconfirmedOf($this->userId);
        $ttl = Hilos::$env[EnvConstants::HILOS_VERIFICATION_TTL_SEC]->int();
        if (
            $pending === null
            || $pending->createdAt < TimeHelper::nowMs() - $ttl * TimeConstants::MS_PER_SECOND
            || ($ask->authenticatorId !== null && $ask->authenticatorId !== $pending->id)
        ) {
            return new UserSecondFactorEnrollConfirmDoneSignalData($ask, false, false, SecondFactorMessages::SETUP_EXPIRED, null, null);
        }

        if (!$this->acceptAppCode([$pending], $ask->code)) {
            return new UserSecondFactorEnrollConfirmDoneSignalData($ask, true, false, SecondFactorMessages::INVALID_CODE, null, null);
        }

        $first = Hilos::$db->secondFactors->confirmedOf($this->userId) === [];
        $name = mb_substr(trim($ask->label), 0, self::LABEL_MAX);
        $pending->actions->confirm($name === '' ? SecondFactorMessages::DEFAULT_LABEL : $name);

        return new UserSecondFactorEnrollConfirmDoneSignalData($ask, false, $first, null, null, null);
    }

    /**
     * Disconnects one of the person's apps; the last one takes the whole second factor with it.
     *
     * The last app cannot go while an administrator requires the factor. When it goes, the removal
     * that stands is canceled - without a letter, the person is the one switching it off - and every
     * app, backup code and trusted browser leaves in one transaction with it.
     *
     * @param UserSecondFactorRemoveSignalData $ask The app and the code proving the person
     * @return UserSecondFactorRemoveDoneSignalData Whether the app went, and whether the factor went whole
     * @throws HilosException When a lookup, a write, the env read or the transaction fails
     * @throws JsonException When a saved merge result cannot be decoded
     */
    public function remove(UserSecondFactorRemoveSignalData $ask): UserSecondFactorRemoveDoneSignalData
    {
        AccountMergeAccess::requireProtectionChange($this->userId);
        $factors = Hilos::$db->secondFactors->confirmedOf($this->userId);
        $target = null;
        foreach ($factors as $factor) {
            if ($factor->id === $ask->authenticatorId) {
                $target = $factor;
            }
        }
        if ($target === null) {
            return new UserSecondFactorRemoveDoneSignalData($ask, false, null, null, false, SecondFactorMessages::NOT_CONNECTED, null, null);
        }

        $last = count($factors) === 1;
        if ($last && SecondFactorPolicy::current()->requiresFor($this->userId)) {
            return new UserSecondFactorRemoveDoneSignalData($ask, false, null, null, false, SecondFactorMessages::REQUIRED, null, null);
        }

        $check = $this->check($ask->proofCode, $ask->proofBackup);
        if (!$check->proven) {
            return new UserSecondFactorRemoveDoneSignalData(
                $ask,
                $check->missed,
                $check->lockMisses,
                $check->lockUntil,
                false,
                $check->refusal,
                null,
                null,
            );
        }

        if (!$last) {
            $target->actions->delete();

            return new UserSecondFactorRemoveDoneSignalData($ask, false, null, null, false, null, null, null);
        }

        Database::transactionStart();
        try {
            Hilos::$db->secondFactorResets->liveOf($this->userId)?->actions->cancel();
            $this->takeFactorOut();
            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollbackSilent();

            throw $failure;
        }

        return new UserSecondFactorRemoveDoneSignalData($ask, false, null, null, true, null, null, null);
    }

    /**
     * Cancels a removal of the person's factor - the one a link names, or the one that stands.
     *
     * A removal that is gone, is someone else's or no longer stands is not canceled, and that is
     * an answer rather than a refusal: the library decides what the profile and the link are told.
     *
     * @param UserSecondFactorResetCancelSignalData $ask The removal to cancel, or none for the standing one
     * @return UserSecondFactorResetCancelDoneSignalData Whether this ask canceled it
     * @throws HilosException When the lookup or the write fails
     * @throws JsonException When a saved merge result cannot be decoded
     */
    public function cancelReset(UserSecondFactorResetCancelSignalData $ask): UserSecondFactorResetCancelDoneSignalData
    {
        AccountMergeAccess::requireProtectionChange($this->userId);
        $reset = $ask->resetId === null
            ? Hilos::$db->secondFactorResets->liveOf($this->userId)
            : Hilos::$db->secondFactorResets[$ask->resetId] ?? null;
        $canceled = $reset !== null && $reset->userId === $this->userId && $reset->actions->cancel();

        return new UserSecondFactorResetCancelDoneSignalData($ask, $canceled, null, null, null);
    }

    /**
     * Stores the removal wait the person chose: longer at once, shorter after the wait in force.
     *
     * The bounds were held by the library. The person's settings row is created by this first choice
     * when it is not there yet.
     *
     * @param UserSecondFactorWaitWriteSignalData $ask The wait asked for
     * @return UserSecondFactorWaitWriteDoneSignalData The wait stored
     * @throws HilosException When the lookup, the settings read or the write fails
     * @throws JsonException When a saved merge result cannot be decoded
     */
    public function writeWait(UserSecondFactorWaitWriteSignalData $ask): UserSecondFactorWaitWriteDoneSignalData
    {
        AccountMergeAccess::requireProtectionChange($this->userId);
        $wait = SecondFactorResetWait::of($this->userId)->withRequested(
            $ask->days,
            SecondFactorPolicy::current(),
            TimeHelper::nowMs(),
        );
        Hilos::$db->secondFactorSettings->actions->setResetWait(
            $this->userId,
            $wait->days,
            $wait->pendingDays,
            $wait->pendingFrom,
        );

        return new UserSecondFactorWaitWriteDoneSignalData($ask, null, null, null);
    }

    /**
     * Replaces the person's unfinished enrollment in one transaction, leaving confirmed apps intact.
     *
     * @param UserSecondFactorEnrollStartSignalData $ask Enrollment requested
     * @return UserSecondFactorEnrollStartDoneSignalData Committed enrollment material
     * @throws HilosException When addressability, reservation, a lookup or a write refuses
     * @throws JsonException When a saved merge result cannot be decoded
     * @throws RandomException When a secret cannot be generated
     */
    public function startEnrolment(UserSecondFactorEnrollStartSignalData $ask): UserSecondFactorEnrollStartDoneSignalData
    {
        AddressablePerson::require($this->userId);
        AccountMergeAccess::requireProtectionChange($this->userId);
        Database::transactionStart();
        try {
            $secret = Totp::newSecret();
            $account = Hilos::$db->identities->findVerifiedEmailByUser($this->userId)
                ?? Hilos::$db->identities->findVerifiedSmsByUser($this->userId)
                ?? (string)$this->userId;
            $uri = OtpAuthUri::build(AuthenticatorName::fromEnv(), $account, $secret);
            Hilos::$db->secondFactors->actions->dropUnfinished($this->userId);
            $factor = Hilos::$db->secondFactors->actions->startEnrolment($this->userId, SecondFactorMessages::DEFAULT_LABEL, $secret);
            $enrollment = new SecondFactorEnrollmentData((int)$factor->id, $secret, $uri);
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }

        return new UserSecondFactorEnrollStartDoneSignalData($ask, $enrollment, null, null, null);
    }

    /**
     * Replaces every spent and unspent backup code atomically.
     *
     * @param UserSecondFactorCodesIssueSignalData $ask Replacement requested
     * @return UserSecondFactorCodesIssueDoneSignalData Committed display codes
     * @throws HilosException When addressability, reservation, a lookup or a write refuses
     * @throws JsonException When a saved merge result cannot be decoded
     * @throws RandomException When codes cannot be generated
     */
    public function issueCodes(UserSecondFactorCodesIssueSignalData $ask): UserSecondFactorCodesIssueDoneSignalData
    {
        AddressablePerson::require($this->userId);
        AccountMergeAccess::requireProtectionChange($this->userId);
        Database::transactionStart();
        try {
            $codes = BackupCodeGenerator::generate(SecondFactorPolicy::current()->backupCodes);
            Hilos::$db->secondFactorBackupCodes->actions->deleteForUser($this->userId);
            Hilos::$db->secondFactorBackupCodes->actions->issueSet($this->userId, $codes);
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }

        return new UserSecondFactorCodesIssueDoneSignalData($ask, array_map(BackupCodeGenerator::display(...), $codes), null, null, null);
    }

    /**
     * Opens a delayed reset and sends its cancel link only after the transaction commits.
     *
     * @param UserSecondFactorResetRequestSignalData $ask Reset requested
     * @return UserSecondFactorResetRequestDoneSignalData Committed reset id and epoch milliseconds
     * @throws HilosException When addressability, reservation, a lookup, write or notification refuses
     * @throws JsonException When a saved merge result cannot be decoded
     * @throws RandomException When the cancel token cannot be generated
     */
    public function requestReset(UserSecondFactorResetRequestSignalData $ask): UserSecondFactorResetRequestDoneSignalData
    {
        AddressablePerson::require($this->userId);
        AccountMergeAccess::requireProtectionChange($this->userId);
        Database::transactionStart();
        try {
            $standing = Hilos::$db->secondFactorResets->liveOf($this->userId);
            if ($standing !== null) {
                throw new ValidationException(sprintf(
                    SecondFactorMessages::RESET_ALREADY_REQUESTED,
                    gmdate('Y-m-d H:i:s', intdiv($standing->effectiveAt, TimeConstants::MS_PER_SECOND)),
                ));
            }
            $nowMs = TimeHelper::nowMs();
            $effectiveAtMs = $nowMs + SecondFactorResetWait::of($this->userId)->effectiveDays(SecondFactorPolicy::current(), $nowMs)
                * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
            $token = RandomHelper::secureHex(self::TOKEN_BYTES);
            $reset = Hilos::$db->secondFactorResets->actions->request($this->userId, $effectiveAtMs, $token);
            $resetId = (int)$reset->id;
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
        SecondFactorResetNotifier::requested($this->userId, $token, $effectiveAtMs);

        return new UserSecondFactorResetRequestDoneSignalData($ask, $resetId, $effectiveAtMs, null, null, null);
    }

    /**
     * Carries out a removal whose moment came: marks it done and takes the factor out, in one transaction.
     *
     * The mark is conditional, so a cancel that won the same minute leaves the factor where it was,
     * and the sweep's frame sent again before the answer finds the removal ended and does nothing.
     * The person's own wait stays - it is a choice about the next factor as much as this one.
     *
     * @param UserSecondFactorResetDueSignalData $request The removal that is due
     * @return UserSecondFactorResetDueDoneSignalData Whether this frame carried it out
     * @throws HilosException When the lookup, a write or the transaction fails
     * @throws JsonException When a saved merge result cannot be decoded
     */
    public function carryOutReset(UserSecondFactorResetDueSignalData $request): UserSecondFactorResetDueDoneSignalData
    {
        AccountMergeAccess::requireProtectionChange($this->userId);
        if (!isset(Hilos::$db->secondFactorResets[$request->resetId])
            || Hilos::$db->secondFactorResets[$request->resetId]->userId !== $this->userId) {
            return new UserSecondFactorResetDueDoneSignalData($request, false, null, null, null);
        }

        $reset = Hilos::$db->secondFactorResets[$request->resetId];
        Database::transactionStart();
        try {
            if (!$reset->actions->complete()) {
                Database::transactionRollback();

                return new UserSecondFactorResetDueDoneSignalData($request, false, null, null, null);
            }
            $this->takeFactorOut();
            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollbackSilent();

            throw $failure;
        }

        return new UserSecondFactorResetDueDoneSignalData($request, true, null, null, null);
    }

    /**
     * Marks a waiting removal reminded, if it still stands and was not reminded since the bound.
     *
     * @param UserSecondFactorResetRemindSignalData $request The removal and the bound
     * @return UserSecondFactorResetRemindDoneSignalData Whether this frame put the mark
     * @throws HilosException When the lookup or the write fails
     * @throws JsonException When a saved merge result cannot be decoded
     */
    public function remind(UserSecondFactorResetRemindSignalData $request): UserSecondFactorResetRemindDoneSignalData
    {
        AccountMergeAccess::requireProtectionChange($this->userId);
        if (!isset(Hilos::$db->secondFactorResets[$request->resetId])) {
            return new UserSecondFactorResetRemindDoneSignalData($request, false, null, null, null);
        }

        $reset = Hilos::$db->secondFactorResets[$request->resetId];
        $marked = $reset->userId === $this->userId
            && $reset->actions->remind($request->notifiedBefore);

        return new UserSecondFactorResetRemindDoneSignalData($request, $marked, null, null, null);
    }

    /**
     * Lifts the person's app-code lock with its step, the miss count and its window (HIL-1285).
     *
     * The operator's way out for a person a guesser locked out; the person is not notified.
     *
     * @param UserSecondFactorUnlockSignalData $request The parked command
     * @return UserSecondFactorUnlockDoneSignalData End of the lifted lock when one was in force
     * @throws HilosException When the lookup or the write fails
     * @throws JsonException When a saved merge result cannot be decoded
     */
    public function unlock(UserSecondFactorUnlockSignalData $request): UserSecondFactorUnlockDoneSignalData
    {
        AccountMergeAccess::requireProtectionChange($this->userId);
        $lockedUntil = Hilos::$db->secondFactorSettings->actions->unlockAppCodes($this->userId);

        return new UserSecondFactorUnlockDoneSignalData(
            $request,
            $lockedUntil,
            null,
            null,
            null,
        );
    }

    /**
     * Checks a code that proves the person, making the writes the check is.
     *
     * A backup code burns, or misses uncounted. An app code under the lock is refused unchecked and
     * counted nowhere, so a right code is refused as a wrong one is and the lock tells a guesser
     * nothing. A right app code takes its step; a wrong one is counted, and at the ceiling the lock
     * takes the next step of the ladder and the count starts again - the miss that put it says so,
     * and of two that reached the ceiling at once only one does. Either is refused with the lock.
     *
     * @param string $code Code as typed
     * @param bool $backupCode Whether it is a backup code
     * @return SecondFactorProofCheck What the check came to
     * @throws HilosException When a lookup, a write or the env read fails
     */
    private function check(string $code, bool $backupCode): SecondFactorProofCheck
    {
        if ($backupCode) {
            $row = Hilos::$db->secondFactorBackupCodes->findUnused($this->userId, BackupCodeGenerator::normalize($code));
            if ($row !== null && $row->actions->spend()) {
                return new SecondFactorProofCheck(true, false, null, null, null);
            }

            return new SecondFactorProofCheck(false, true, null, null, SecondFactorMessages::INVALID_CODE);
        }

        $lockedUntil = isset(Hilos::$db->secondFactorSettings[$this->userId])
            ? Hilos::$db->secondFactorSettings[$this->userId]->appCodeLockedUntil
            : null;
        $nowMs = TimeHelper::nowMs();
        if ($lockedUntil !== null && $lockedUntil > $nowMs) {
            return new SecondFactorProofCheck(
                false,
                false,
                null,
                null,
                sprintf(
                    SecondFactorMessages::APP_CODES_LOCKED,
                    SecondFactorLockPolicy::fromEnv()->waitText(
                        (int)ceil(($lockedUntil - $nowMs) / TimeConstants::MS_PER_SECOND),
                    ),
                ),
            );
        }

        if ($this->acceptAppCode(Hilos::$db->secondFactors->confirmedOf($this->userId), $code)) {
            return new SecondFactorProofCheck(true, false, null, null, null);
        }

        $policy = SecondFactorLockPolicy::fromEnv();
        $misses = Hilos::$db->secondFactorSettings->actions->countAppCodeMiss($this->userId, $policy->windowSeconds());
        if ($misses < $policy->misses()) {
            return new SecondFactorProofCheck(false, true, null, null, SecondFactorMessages::INVALID_CODE);
        }

        $nowMs = TimeHelper::nowMs();
        $setting = Hilos::$db->secondFactorSettings[$this->userId];
        $lastUntil = $setting->appCodeLockedUntil;
        $step = $policy->nextStep((int)$setting->appCodeLockStep, $lastUntil, $nowMs);
        $untilMs = $nowMs + $policy->lockSecondsFor($step) * TimeConstants::MS_PER_SECOND;
        $locked = Hilos::$db->secondFactorSettings->actions->lockAppCodes(
            $this->userId,
            $policy->misses(),
            $step,
            $untilMs,
        );

        return new SecondFactorProofCheck(
            false,
            true,
            $locked ? $misses : null,
            $locked ? $untilMs : null,
            sprintf(
                SecondFactorMessages::APP_CODES_LOCKED,
                $policy->waitText((int)ceil(($untilMs - $nowMs) / TimeConstants::MS_PER_SECOND)),
            ),
        );
    }

    /**
     * Checks a code from an app against apps, taking its step on the one it matched.
     *
     * @param list<SecondFactor> $factors Apps to check against
     * @param string $code Code as typed
     * @return bool True when the code matched one and its step was not taken before
     * @throws HilosException When a lookup or the write fails
     */
    private function acceptAppCode(array $factors, string $code): bool
    {
        $now = time();
        foreach ($factors as $factor) {
            $secret = $factor->readSecret();
            $step = $secret === null ? null : Totp::verify($secret, $code, $now);
            if ($step !== null && $factor->actions->acceptStep($step)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes every app and backup code of the person - the factor switched off whole - and the
     * trust of every browser that skipped it (HIL-1407).
     *
     * Inside the caller's transaction: a browser trusted to skip a factor that is gone would skip
     * the next one too.
     *
     * @throws HilosException When a delete fails
     */
    private function takeFactorOut(): void
    {
        Hilos::$db->secondFactors->actions->deleteForUser($this->userId);
        Hilos::$db->secondFactorBackupCodes->actions->deleteForUser($this->userId);
        Hilos::$db->secondFactorTrusts->actions->deleteForUser($this->userId);
    }
}
