<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\View\Item\SecondFactor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmSignalData;
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

/**
 * The edits of one person's second factor, written by that person's agent (HIL-1406).
 *
 * {@see AbstractUserAgent} hands each frame of {@see AbstractUsersLibraryAgent} here and sends the
 * answer back. Everything written is the person's own set: an app's step taken and the app
 * confirmed or removed, a backup code burned, the wrong app codes counted and the lock they put or
 * an operator lifted, the removal wait chosen, a removal canceled, carried out or marked reminded.
 * Creating - an enrolment started, a set of backup codes issued, a removal asked for - stays with
 * the library.
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
     *
     * @param UserSecondFactorProveSignalData $ask The code and where it was typed
     * @return UserSecondFactorProveDoneSignalData Whether it proved the person, what it counted, and what it canceled
     * @throws HilosException When a lookup, a write or the env read fails
     */
    public function prove(UserSecondFactorProveSignalData $ask): UserSecondFactorProveDoneSignalData
    {
        $check = $this->check($ask->code, $ask->backupCode);
        $resetCanceled = $check->proven
            && $ask->cancelReset
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
     */
    public function enrollConfirm(UserSecondFactorEnrollConfirmSignalData $ask): UserSecondFactorEnrollConfirmDoneSignalData
    {
        $pending = Hilos::$db->secondFactors->unconfirmedOf($this->userId);
        $ttl = Hilos::$env[EnvConstants::HILOS_VERIFICATION_TTL_SEC]->int();
        if (
            $pending === null
            || strtotime($pending->createdAt) < time() - $ttl
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
     * app and backup code leaves in one transaction with it.
     *
     * @param UserSecondFactorRemoveSignalData $ask The app and the code proving the person
     * @return UserSecondFactorRemoveDoneSignalData Whether the app went, and whether the factor went whole
     * @throws HilosException When a lookup, a write, the env read or the transaction fails
     */
    public function remove(UserSecondFactorRemoveSignalData $ask): UserSecondFactorRemoveDoneSignalData
    {
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
            $this->rollBack();

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
     */
    public function cancelReset(UserSecondFactorResetCancelSignalData $ask): UserSecondFactorResetCancelDoneSignalData
    {
        $reset = $ask->resetId === null
            ? Hilos::$db->secondFactorResets->liveOf($this->userId)
            : Hilos::$db->secondFactorResets[$ask->resetId];
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
     */
    public function writeWait(UserSecondFactorWaitWriteSignalData $ask): UserSecondFactorWaitWriteDoneSignalData
    {
        $wait = SecondFactorResetWait::of($this->userId)->withRequested($ask->days, SecondFactorPolicy::current(), time());
        Hilos::$db->secondFactorSettings->actions->setResetWait(
            $this->userId,
            $wait->days,
            $wait->pendingDays,
            $wait->pendingFromSec === null ? null : date('Y-m-d H:i:s', $wait->pendingFromSec),
        );

        return new UserSecondFactorWaitWriteDoneSignalData($ask, null, null, null);
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
     */
    public function carryOutReset(UserSecondFactorResetDueSignalData $request): UserSecondFactorResetDueDoneSignalData
    {
        $reset = Hilos::$db->secondFactorResets[$request->resetId];
        if ($reset === null || $reset->userId !== $this->userId) {
            return new UserSecondFactorResetDueDoneSignalData($request, false, null, null, null);
        }

        Database::transactionStart();
        try {
            if (!$reset->actions->complete()) {
                Database::transactionRollback();

                return new UserSecondFactorResetDueDoneSignalData($request, false, null, null, null);
            }
            $this->takeFactorOut();
            Database::transactionCommit();
        } catch (HilosException $failure) {
            $this->rollBack();

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
     */
    public function remind(UserSecondFactorResetRemindSignalData $request): UserSecondFactorResetRemindDoneSignalData
    {
        $reset = Hilos::$db->secondFactorResets[$request->resetId];
        $marked = $reset !== null
            && $reset->userId === $this->userId
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
     */
    public function unlock(UserSecondFactorUnlockSignalData $request): UserSecondFactorUnlockDoneSignalData
    {
        return new UserSecondFactorUnlockDoneSignalData(
            $request,
            Hilos::$db->secondFactorSettings->actions->unlockAppCodes($this->userId),
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

        $lockedUntil = Hilos::$db->secondFactorSettings[$this->userId]?->appCodeLockedUntil;
        $left = $lockedUntil === null ? 0 : (int)strtotime($lockedUntil) - time();
        if ($left > 0) {
            return new SecondFactorProofCheck(
                false,
                false,
                null,
                null,
                sprintf(SecondFactorMessages::APP_CODES_LOCKED, SecondFactorLockPolicy::fromEnv()->waitText($left)),
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

        $now = time();
        $setting = Hilos::$db->secondFactorSettings[$this->userId];
        $lastUntil = $setting?->appCodeLockedUntil;
        $step = $policy->nextStep((int)$setting?->appCodeLockStep, $lastUntil === null ? null : (int)strtotime($lastUntil), $now);
        $untilSec = $now + $policy->lockSecondsFor($step);
        $locked = Hilos::$db->secondFactorSettings->actions->lockAppCodes(
            $this->userId,
            $policy->misses(),
            $step,
            date('Y-m-d H:i:s', $untilSec),
        );

        return new SecondFactorProofCheck(
            false,
            true,
            $locked ? $misses : null,
            $locked ? $untilSec : null,
            sprintf(SecondFactorMessages::APP_CODES_LOCKED, $policy->waitText($untilSec - $now)),
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
     * Deletes every app and backup code of the person - the factor switched off whole.
     *
     * @throws HilosException When a delete fails
     */
    private function takeFactorOut(): void
    {
        Hilos::$db->secondFactors->actions->deleteForUser($this->userId);
        Hilos::$db->secondFactorBackupCodes->actions->deleteForUser($this->userId);
    }

    /**
     * Rolls back a failed switch-off without letting the cleanup replace the failure.
     *
     * The connection under the transaction belongs to the worker and outlives the frame, so a
     * transaction left open would take in every later write that worker makes.
     */
    private function rollBack(): void
    {
        try {
            Database::transactionRollback();
        } catch (HilosException) {
            // Reporting the cleanup would replace the failure the caller is owed
        }
    }
}
