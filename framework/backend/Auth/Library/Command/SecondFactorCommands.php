<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Closure;
use Hilos\Auth\AuthenticatorName;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\ConfirmSecondFactorActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorResetCancelLinkActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorSetupConfirmActionDTO;
use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesRenewActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesShowActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollConfirmActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollStartActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorRemoveActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetWaitSetActionDTO;
use Hilos\Auth\SecondFactor\DTO\SecondFactorProfileReplyDTO;
use Hilos\Auth\SecondFactor\DTO\SecondFactorStepData;
use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\SecondFactor\OtpAuthUri;
use Hilos\Auth\SecondFactor\SecondFactorLockNotifier;
use Hilos\Auth\SecondFactor\SecondFactorMessages;
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\SecondFactor\SecondFactorPersonEdits;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorResetNotifier;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\SecondFactorResetWait;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Database\Database;
use Hilos\Database\View\Item\SecondFactorReset;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\AddressablePerson;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmSignalData;
use Hilos\Users\DTO\UserSecondFactorProveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorProveSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorWaitWriteSignalData;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use Random\RandomException;
use Throwable;

/**
 * The second factor's commands: the code step of a sign-in, enrolment on the way in, the profile,
 * and the delayed removal (HIL-494).
 *
 * A sign-in reaches these already proven and held: the session holder
 * ({@see AbstractSessionsLibraryAgent}) wrote the wait on the session row, naming the person
 * and the screen. Every command here reads whose sign-in it is off that wait - never off the
 * browser - and refuses a wait on another screen with the one sentence that sends the person
 * back to start. A wait that ran out is answered with the address field and its reason, and
 * the holder lets it go.
 *
 * Here are the checks, the creation and what follows; the writes of one person's factor are that
 * person's agent's (HIL-1406). A command judges what lies outside the person's set - the wait on
 * the browser, the confirmation of an operation, the administrator's bounds, whether the person
 * can be addressed - and refuses at once. What passes becomes a frame to the person's agent
 * ({@see AbstractUserAgent}, {@see SecondFactorPersonEdits}): a code checked, which is its own
 * write - a step taken, a backup code burned, a wrong app code counted against the person's
 * ceiling (HIL-1285) and the lock it puts - an app confirmed or disconnected, a removal canceled,
 * the wait chosen. The browser action is resumed on the agent's answer: the after... methods do
 * what follows any answer - a wrong code told to the holder, a lock or a canceled removal mailed -
 * and the finish... methods continue the action that asked. What is created stays here, in one
 * operation with what it replaces: an enrolment started, a set of backup codes issued, a removal
 * asked for.
 *
 * The profile half works on a signed-in person, whose id comes off the session: every action
 * but the first enrolment, the wait and the removal starts with a code, because a stolen live
 * session must not strip or copy the factor. After every write the person's section is fanned
 * to their group, whichever tab or browser made it.
 *
 * The removals are found by the users library's sweep, which hands each due one and each one
 * owing a reminder to the person's agent; the answers come back here ({@see finishResetDue()},
 * {@see finishResetRemind()}).
 */
final class SecondFactorCommands extends AbstractLibraryCommands
{
    /** Bytes of a cancel link's token. */
    private const int TOKEN_BYTES = 32;

    /**
     * Checks the code of a sign-in held on its second factor; the person's agent checks it.
     *
     * The wait on this browser is read here. The code goes to the person's agent, which takes a
     * matched step or burns a backup code, counts a wrong app code against the ceiling and ends
     * a removal that stands - whoever shows the factor has not lost it. The sign-in is let through
     * on its answer ({@see finishProof()}).
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ConfirmSecondFactorActionDTO $dto Code, its kind, and whether to trust the browser
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When no wait stands on the code step, or the person can no longer be addressed
     * @throws HilosException When a lookup or a frame fails
     */
    public function confirm(string $acceptKey, ConfirmSecondFactorActionDTO $dto): void
    {
        $userId = $this->waitingUser($this->acting($acceptKey), [SecondFactorPendingMode::VERIFY, SecondFactorPendingMode::SETUP_DONE]);
        if ($userId === null) {
            return;
        }

        $this->askProof($userId, $acceptKey, $dto->code, $dto->backupCode, cancelReset: true, trustDevice: $dto->trustDevice);
    }

    /**
     * Lets this browser's second-factor wait go - the person signs in another way.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws HilosException When the frame cannot be sent
     */
    public function cancel(string $acceptKey): void
    {
        $this->library->announceSecondFactorCanceled($this->acting($acceptKey));
    }

    /**
     * Opens the enrolment an administrator requires, answering the secret once.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @return ?AuthFlowOutcome The secret and its otpauth address, or null when the holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When no wait stands on the enrolment screen
     * @throws RandomException When the secret cannot be drawn
     * @throws HilosException When a lookup, a write or a frame fails
     */
    public function setupStart(string $acceptKey): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);
        $userId = $this->waitingUser($acting, [SecondFactorPendingMode::SETUP]);
        if ($userId === null) {
            return null;
        }

        [, $secret, $uri] = $this->startEnrolment($userId);

        return AuthFlowOutcome::secondFactorData(
            new SecondFactorStepData(SecondFactorPolicy::current()->trustDeviceDays(), null, $secret, $uri),
        );
    }

    /**
     * Confirms the enrolment on the way in with its first code; the person's agent confirms it.
     *
     * The codes are answered on the agent's answer ({@see finishEnrollConfirm()}), and the surface
     * moves to the screen that shows them; the person is let in by the Continue under them
     * ({@see setupFinish()}).
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param SecondFactorSetupConfirmActionDTO $dto First code and the name of the app
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When no wait stands on the enrolment, or the person can no longer be addressed
     * @throws HilosException When a lookup or a frame fails
     */
    public function setupConfirm(string $acceptKey, SecondFactorSetupConfirmActionDTO $dto): void
    {
        $userId = $this->waitingUser($this->acting($acceptKey), [SecondFactorPendingMode::SETUP]);
        if ($userId === null) {
            return;
        }

        $this->askEnrollConfirm($userId, $acceptKey, null, $dto->code, $dto->label);
    }

    /**
     * Lets the person in after the backup codes of an enrolment on the way in.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When no wait stands on the last screen of an enrolment
     * @throws HilosException When a lookup or a frame fails
     */
    public function setupFinish(string $acceptKey): void
    {
        $acting = $this->acting($acceptKey);
        $userId = $this->waitingUser($acting, [SecondFactorPendingMode::SETUP_DONE]);
        if ($userId === null) {
            return;
        }

        $this->library->grantSession($acting, $userId, secondFactorProven: true);
    }

    /**
     * Asks the delayed removal of the second factor from the code step.
     *
     * The sign-in is let go - it cannot go on without the factor - and the person is shown the
     * date the removal takes effect.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When no wait stands on the code step, or a removal already stands
     * @throws RandomException When the cancel token cannot be drawn
     * @throws HilosException When a lookup, a write or a frame fails
     */
    public function resetRequest(string $acceptKey): void
    {
        $acting = $this->acting($acceptKey);
        $userId = $this->waitingUser($acting, [SecondFactorPendingMode::VERIFY, SecondFactorPendingMode::SETUP_DONE]);
        if ($userId === null) {
            return;
        }

        $reset = $this->requestReset($userId);
        $this->publishState($userId);
        $this->library->announceSecondFactorCanceled(
            $acting,
            AuthFlowOutcome::moveToSecondFactor(
                AuthFlowStep::SECOND_FACTOR_RESET_REQUESTED,
                new SecondFactorStepData(
                    SecondFactorPolicy::current()->trustDeviceDays(),
                    TimeHelper::sqlToMs($reset->effectiveAt),
                ),
                null,
            ),
        );
    }

    /**
     * Cancels a removal by the "it was not me" link, without signing in.
     *
     * The link names the removal by its token, which is found here; the person's agent cancels it
     * by a conditional write, and the relay screen is answered on its answer
     * ({@see finishResetCancel()}). A link naming nothing standing, or a person who can no longer
     * be addressed, is the one dead-link sentence.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param SecondFactorResetCancelLinkActionDTO $dto Token the link carried
     * @throws ValidationException When the link names no standing removal of a person that can be addressed
     * @throws HilosException When the lookup or the frame fails
     */
    public function resetCancelLink(string $acceptKey, SecondFactorResetCancelLinkActionDTO $dto): void
    {
        $reset = $dto->token === ''
            ? null
            : Hilos::$db->secondFactorResets->findLiveByToken($dto->token);
        if ($reset === null) {
            throw new ValidationException(SecondFactorMessages::LINK_DEAD);
        }

        try {
            AddressablePerson::require($reset->userId);
        } catch (ValidationException) {
            throw new ValidationException(SecondFactorMessages::LINK_DEAD);
        }
        $this->askResetCancel($reset->userId, $acceptKey, (int)$reset->id);
    }

    /**
     * Opens a delayed removal of a person's second factor and announces it.
     *
     * Shared by the code step and the profile. The wait is the person's own, taken as it stands
     * at the moment of asking. The row and its token commit together, and only then does the
     * first announcement carry the cancel link.
     *
     * @param int $userId Person whose factor is to be removed
     * @return SecondFactorReset The removal asked for
     * @throws ValidationException When a removal already stands
     * @throws RandomException When the cancel token cannot be drawn
     * @throws HilosException When a lookup, the write or the announcement fails
     */
    public function requestReset(int $userId): SecondFactorReset
    {
        $standing = Hilos::$db->secondFactorResets->liveOf($userId);
        if ($standing !== null) {
            throw new ValidationException(sprintf(SecondFactorMessages::RESET_ALREADY_REQUESTED, $standing->effectiveAt));
        }

        $now = time();
        $effectiveAtSec = $now + SecondFactorResetWait::of($userId)->effectiveDays(SecondFactorPolicy::current(), $now)
            * TimeConstants::SECONDS_PER_DAY;
        $token = RandomHelper::secureHex(self::TOKEN_BYTES);
        Database::transactionStart();
        try {
            $reset = Hilos::$db->secondFactorResets->actions->request(
                $userId,
                date('Y-m-d H:i:s', $effectiveAtSec),
                $token,
            );
            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollback();
            throw $failure;
        }
        SecondFactorResetNotifier::requested($userId, $token, $effectiveAtSec);

        return $reset;
    }

    /**
     * Opens an enrolment for a person: a fresh secret on an unconfirmed authenticator.
     *
     * @param int $userId Person enrolling
     * @return array{0: int, 1: string, 2: string} The unconfirmed authenticator, its base32 secret and otpauth address
     * @throws RandomException When the secret cannot be drawn
     * @throws HilosException When a lookup, the env read or the write fails
     */
    public function startEnrolment(int $userId): array
    {
        $secret = Totp::newSecret();
        $factor = Hilos::$db->secondFactors->actions->startEnrolment($userId, SecondFactorMessages::DEFAULT_LABEL, $secret);
        $account = Hilos::$db->identities->findVerifiedEmailByUser($userId)
            ?? Hilos::$db->identities->findVerifiedSmsByUser($userId)
            ?? (string)$userId;

        return [
            (int)$factor->id,
            $secret,
            OtpAuthUri::build(AuthenticatorName::fromEnv(), $account, $secret),
        ];
    }

    /**
     * Issues a person a new set of backup codes, the old set dying with it.
     *
     * @param int $userId Person
     * @return list<string> The new codes in display form
     * @throws RandomException When the codes cannot be drawn
     * @throws HilosException When a write fails
     */
    public function issueBackupCodes(int $userId): array
    {
        $codes = BackupCodeGenerator::generate(SecondFactorPolicy::current()->backupCodes);
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($userId, $codes);

        return array_map(BackupCodeGenerator::display(...), $codes);
    }

    /**
     * Starts connecting an authenticator app from the profile, answering the secret once.
     *
     * Connecting an app is the operation 'add_authenticator_app' (HIL-1138): the first app needs
     * a live confirmation, while a second one proves itself with a code from a connected app,
     * which the gate takes for the confirmation. That code is asked here whether or not an
     * administrator switched the operation off, and checked by the person's agent; the enrolment
     * is started on its answer ({@see finishProof()}).
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorEnrollStartActionDTO $dto Proof, when an app is already connected
     * @return ?SecondFactorProfileReplyDTO The enrolment, its secret and otpauth address, or null when the code went to the agent
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the first app is not confirmed, or the person can no longer be addressed
     * @throws RandomException When the secret cannot be drawn
     * @throws HilosException When a lookup, a write or a frame fails
     */
    public function profileEnrollStart(string $acceptKey, ProfileSecondFactorEnrollStartActionDTO $dto): ?SecondFactorProfileReplyDTO
    {
        $userId = (int)$this->confirmedUser($acceptKey, StepUpOperationKey::ADD_AUTHENTICATOR_APP)->userId;
        if (Hilos::$db->secondFactors->confirmedOf($userId) !== []) {
            $this->askProof($userId, $acceptKey, (string)$dto->proofCode, $dto->proofBackup);

            return null;
        }

        [$id, $secret, $uri] = $this->startEnrolment($userId);

        return new SecondFactorProfileReplyDTO(authenticatorId: $id, secret: $secret, otpauthUri: $uri);
    }

    /**
     * Confirms an app being connected from the profile with its first code.
     *
     * The second step of the same operation (HIL-1138): the confirmation is asked again, since
     * it may have run out while the person was scanning the code. Whether the enrolment the form
     * names still stands, and the code, are the person's agent's to check
     * ({@see finishEnrollConfirm()}).
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorEnrollConfirmActionDTO $dto Enrolment, first code and name
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the add is not confirmed, or the person can no longer be addressed
     * @throws HilosException When a lookup or the frame fails
     */
    public function profileEnrollConfirm(string $acceptKey, ProfileSecondFactorEnrollConfirmActionDTO $dto): void
    {
        $userId = (int)$this->confirmedUser($acceptKey, StepUpOperationKey::ADD_AUTHENTICATOR_APP)->userId;
        $this->askEnrollConfirm($userId, $acceptKey, $dto->authenticatorId, $dto->code, $dto->label);
    }

    /**
     * Disconnects an app from the profile; the last one takes the whole second factor with it.
     *
     * Whether the app is the person's, whether it is the last, whether an administrator requires
     * the factor and the proof are all asked by the person's agent in the turn that deletes
     * ({@see finishRemove()}).
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorRemoveActionDTO $dto App and the proof
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the person can no longer be addressed
     * @throws HilosException When a lookup or the frame fails
     */
    public function profileRemove(string $acceptKey, ProfileSecondFactorRemoveActionDTO $dto): void
    {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        $this->library->askPersonAgent($userId, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE, new UserSecondFactorRemoveSignalData(
            userId: $userId,
            authenticatorId: $dto->authenticatorId,
            proofCode: $dto->proofCode,
            proofBackup: $dto->proofBackup,
            replySignal: HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->library->currentActionRequestId(),
            action: $this->library->runningAction(),
            successMessage: null,
        ));
    }

    /**
     * Shows the backup codes, used ones included, after a code the person's agent checks.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorCodesShowActionDTO $dto The proof
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the person can no longer be addressed
     * @throws HilosException When a lookup or the frame fails
     */
    public function profileCodesShow(string $acceptKey, ProfileSecondFactorCodesShowActionDTO $dto): void
    {
        $this->askProof((int)$this->actingUser($acceptKey)->userId, $acceptKey, $dto->proofCode, $dto->proofBackup);
    }

    /**
     * Issues a new set of backup codes after a code the person's agent checks; the old set dies.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorCodesRenewActionDTO $dto The proof
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the person can no longer be addressed
     * @throws HilosException When a lookup or the frame fails
     */
    public function profileCodesRenew(string $acceptKey, ProfileSecondFactorCodesRenewActionDTO $dto): void
    {
        $this->askProof((int)$this->actingUser($acceptKey)->userId, $acceptKey, $dto->proofCode, $dto->proofBackup);
    }

    /**
     * Chooses the person's removal wait inside the administrator's bounds; the person's agent stores it.
     *
     * The agent measures it against the wait in force - longer at once, shorter after it.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorResetWaitSetActionDTO $dto Wait asked for
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the wait is outside the administrator's bounds, or the person can no longer be addressed
     * @throws HilosException When a lookup, the settings read or the frame fails
     */
    public function profileResetWaitSet(string $acceptKey, ProfileSecondFactorResetWaitSetActionDTO $dto): void
    {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        $policy = SecondFactorPolicy::current();
        $min = max($policy->resetWaitMinDays, SecondFactorSettings::RESET_WAIT_FLOOR_DAYS);
        if ($dto->days < $min || $dto->days > $policy->resetWaitMaxDays) {
            throw new ValidationException(sprintf(SecondFactorMessages::WAIT_OUT_OF_BOUNDS, $min, $policy->resetWaitMaxDays));
        }

        $this->library->askPersonAgent($userId, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE, new UserSecondFactorWaitWriteSignalData(
            userId: $userId,
            days: $dto->days,
            replySignal: HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->library->currentActionRequestId(),
            action: $this->library->runningAction(),
            successMessage: null,
        ));
    }

    /**
     * Asks the delayed removal of the second factor from the profile.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the person has no factor, or a removal already stands
     * @throws RandomException When the cancel token cannot be drawn
     * @throws HilosException When a lookup, the write or the announcement fails
     */
    public function profileResetRequest(string $acceptKey): void
    {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        if (Hilos::$db->secondFactors->confirmedOf($userId) === []) {
            throw new ValidationException(SecondFactorMessages::FACTOR_OFF);
        }

        $this->requestReset($userId);
        $this->publishState($userId);
    }

    /**
     * Cancels the removal that stands, from the profile; the person's agent cancels it.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the person can no longer be addressed
     * @throws HilosException When a lookup or the frame fails
     */
    public function profileResetCancel(string $acceptKey): void
    {
        $this->askResetCancel((int)$this->actingUser($acceptKey)->userId, $acceptKey, null);
    }

    /**
     * Does what follows a code the person's agent checked, whatever came of it (HIL-1406).
     *
     * A code checked and missed on the sign-in step is told to the session holder, which counts
     * it on this browser; the miss that put the lock mails the person - of two that reached the
     * ceiling at once only one says so; a removal this proof canceled mails the person too. A
     * failure of any of them is a log line: the agent's write stands, and the action is answered
     * all the same.
     *
     * @param UserSecondFactorProveDoneSignalData $done The agent's answer
     */
    public function afterProof(UserSecondFactorProveDoneSignalData $done): void
    {
        $ask = $done->ask;
        if ($done->missed && $ask->action === HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR) {
            $this->followUp(fn () => $this->library->announceSecondFactorMissed($this->acting($ask->acceptKey)), 'wrong code', $ask->userId);
        }
        $this->mailLock($ask->userId, $done->lockMisses, $done->lockUntil);
        if ($done->resetCanceled) {
            $this->followUp(fn () => SecondFactorResetNotifier::canceled($ask->userId), 'removal cancel letter', $ask->userId);
        }
    }

    /**
     * Continues the action whose code the person's agent accepted (HIL-1406).
     *
     * The sign-in step lets the sign-in through, and the session holder answers it; starting
     * another app starts its enrolment; showing the backup codes lists them; renewing them issues
     * a new set, the old one dying with it.
     *
     * @param UserSecondFactorProveSignalData $ask The ask the agent answered
     * @return ?ActionReplyDTO What the profile is told, or null when the holder answers
     * @throws ItemNotFoundForUpdateException When the asking connection has no session any more
     * @throws LogicException When the ask names an action no proof continues
     * @throws RandomException When a secret or the backup codes cannot be drawn
     * @throws HilosException When a lookup, a write or a frame fails
     */
    public function finishProof(UserSecondFactorProveSignalData $ask): ?ActionReplyDTO
    {
        switch ($ask->action) {
            case HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR:
                $this->publishState($ask->userId);
                $this->library->grantSession(
                    $this->acting($ask->acceptKey),
                    $ask->userId,
                    secondFactorProven: true,
                    trustDevice: $ask->trustDevice,
                );

                return null;

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START:
                [$id, $secret, $uri] = $this->startEnrolment($ask->userId);

                return new SecondFactorProfileReplyDTO(authenticatorId: $id, secret: $secret, otpauthUri: $uri);

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW:
                $this->publishState($ask->userId);

                return new SecondFactorProfileReplyDTO(codes: Hilos::$db->secondFactorBackupCodes->entriesOf($ask->userId));

            case HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW:
                $codes = $this->issueBackupCodes($ask->userId);
                $this->publishState($ask->userId);

                return new SecondFactorProfileReplyDTO(backupCodes: $codes);

            default:
                throw new LogicException("A second-factor proof continues no action {$ask->action}");
        }
    }

    /**
     * Tells the session holder of a first code that missed on the way in, whatever else came of it (HIL-1406).
     *
     * The first code of a new app is outside the ceiling, so there is no lock to mail. A failure is
     * a log line, as in {@see afterProof()}.
     *
     * @param UserSecondFactorEnrollConfirmDoneSignalData $done The agent's answer
     */
    public function afterEnrollConfirm(UserSecondFactorEnrollConfirmDoneSignalData $done): void
    {
        $ask = $done->ask;
        if ($done->missed && $ask->action === HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM) {
            $this->followUp(fn () => $this->library->announceSecondFactorMissed($this->acting($ask->acceptKey)), 'wrong code', $ask->userId);
        }
    }

    /**
     * Continues an enrolment the person's agent confirmed, issuing the backup codes of a first app (HIL-1406).
     *
     * A first app of the person comes with a set of backup codes; a further one does not - the set
     * the person has stays theirs. On the way in the session holder is told the enrolment is
     * proven and the surface moves to the screen of the codes; in the profile the codes are the
     * answer.
     *
     * @param UserSecondFactorEnrollConfirmDoneSignalData $done The agent's answer
     * @return ActionReplyDTO The codes screen on the way in, or the profile's answer
     * @throws ItemNotFoundForUpdateException When the asking connection has no session any more
     * @throws RandomException When the backup codes cannot be drawn
     * @throws HilosException When a lookup, a write or a frame fails
     */
    public function finishEnrollConfirm(UserSecondFactorEnrollConfirmDoneSignalData $done): ActionReplyDTO
    {
        $ask = $done->ask;
        $codes = $done->first ? $this->issueBackupCodes($ask->userId) : null;
        if ($ask->action !== HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM) {
            $this->publishState($ask->userId);

            return new SecondFactorProfileReplyDTO(backupCodes: $codes);
        }

        $this->library->announceSecondFactorSetupProven($this->acting($ask->acceptKey));
        $this->publishState($ask->userId);

        return AuthFlowOutcome::moveToSecondFactor(
            AuthFlowStep::SECOND_FACTOR_CODES,
            new SecondFactorStepData(SecondFactorPolicy::current()->trustDeviceDays(), null, backupCodes: $codes),
            null,
        );
    }

    /**
     * Mails the lock a wrong proof of a removal put, whatever else came of it (HIL-1406).
     *
     * @param UserSecondFactorRemoveDoneSignalData $done The agent's answer
     */
    public function afterRemove(UserSecondFactorRemoveDoneSignalData $done): void
    {
        $this->mailLock($done->ask->userId, $done->lockMisses, $done->lockUntil);
    }

    /**
     * Continues an app disconnect the person's agent did (HIL-1406).
     *
     * A factor switched off whole is told to the session holder, which revokes the trust every
     * browser of the person held for it.
     *
     * @param UserSecondFactorRemoveDoneSignalData $done The agent's answer
     * @throws HilosException When the section cannot be built, or a frame or the signal cannot be queued
     */
    public function finishRemove(UserSecondFactorRemoveDoneSignalData $done): void
    {
        if ($done->switchedOff) {
            $this->library->announceSecondFactorOff($done->ask->userId);
        }
        $this->publishState($done->ask->userId);
    }

    /**
     * Continues a removal cancel the person's agent looked at (HIL-1406).
     *
     * A cancel is mailed. The profile is answered alike whether there was a removal to cancel; the
     * relay screen of the link shows its success in place - a link that canceled nothing was
     * refused before this.
     *
     * @param UserSecondFactorResetCancelDoneSignalData $done The agent's answer
     * @return ?AuthFlowOutcome Success of the link, or null for the profile
     * @throws HilosException When the announcement, the section or its signal fails
     */
    public function finishResetCancel(UserSecondFactorResetCancelDoneSignalData $done): ?AuthFlowOutcome
    {
        $ask = $done->ask;
        if ($done->canceled) {
            SecondFactorResetNotifier::canceled($ask->userId);
        }
        $this->publishState($ask->userId);
        if ($ask->action !== HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK) {
            return null;
        }

        return AuthFlowOutcome::moveTo(AuthFlowStep::DONE, AuthFlowIntent::LOGIN);
    }

    /**
     * Continues a wait the person's agent stored: the section shows it (HIL-1406).
     *
     * @param UserSecondFactorWaitWriteSignalData $ask The ask the agent answered
     * @throws HilosException When the section cannot be built or the signal queued
     */
    public function finishWaitWrite(UserSecondFactorWaitWriteSignalData $ask): void
    {
        $this->publishState($ask->userId);
    }

    /**
     * Does what follows a removal the person's agent carried out (HIL-1406).
     *
     * The session holder is told the factor is gone, the person is mailed and the section is
     * fanned - only when this answer carried it out: a removal a cancel won, or the sweep's frame
     * sent twice, carries nothing out. A refusal is a log line; the next tick finds the removal
     * still standing and asks again.
     *
     * @param UserSecondFactorResetDueDoneSignalData $done The agent's answer
     * @throws HilosException When a frame, the announcement or the section fails
     */
    public function finishResetDue(UserSecondFactorResetDueDoneSignalData $done): void
    {
        $request = $done->request;
        if ($done->error !== null) {
            Logger::error(
                "Second-factor removal {$request->resetId} of #{$request->userId} was not carried out: "
                    . ($done->errorDetail ?? $done->error),
            );

            return;
        }

        if (!$done->carriedOut) {
            return;
        }

        $this->library->announceSecondFactorOff($request->userId);
        SecondFactorResetNotifier::completed($request->userId);
        $this->publishState($request->userId);
    }

    /**
     * Mails the reminder of a removal the person's agent marked reminded (HIL-1406).
     *
     * Only the answer that put the mark mails, so the reminder goes once a day however often the
     * sweep's frame went. A refusal is a log line.
     *
     * @param UserSecondFactorResetRemindDoneSignalData $done The agent's answer
     * @throws LogicException When the removal has no cancel token any more
     * @throws HilosException When the lookup or the announcement fails
     */
    public function finishResetRemind(UserSecondFactorResetRemindDoneSignalData $done): void
    {
        $request = $done->request;
        if ($done->error !== null) {
            Logger::error(
                "Second-factor removal {$request->resetId} of #{$request->userId} was not marked reminded: "
                    . ($done->errorDetail ?? $done->error),
            );

            return;
        }

        if (!$done->marked) {
            return;
        }

        $reset = Hilos::$db->secondFactorResets[$request->resetId];
        $token = $reset?->readCancelToken();
        if ($reset === null || $token === null) {
            throw new LogicException('A standing second-factor removal ' . $request->resetId . ' has no cancel token');
        }
        SecondFactorResetNotifier::reminder($request->userId, $token, (int)strtotime($reset->effectiveAt));
    }

    /**
     * Fans the person's second-factor section to their group.
     *
     * @param int $userId Person
     * @throws HilosException When the section cannot be built or the signal queued
     */
    public function publishState(int $userId): void
    {
        $this->library->sendToGroup(
            HilosSignalConstants::HILOS_SECOND_FACTOR_STATE,
            SecondFactorGroup::forUser($userId),
            SecondFactorStateProjector::stateFor($userId),
        );
    }

    /**
     * Asks the person's agent to check a code that proves them, and stops owing the browser an answer.
     *
     * @param int $userId Person whose code it is
     * @param string $acceptKey Accept key the action arrived on
     * @param string $code Code as typed
     * @param bool $backupCode Whether it is a backup code
     * @param bool $cancelReset Whether a right code ends the removal that stands - the sign-in step alone
     * @param bool $trustDevice Whether the person asked not to be asked again on this browser
     * @throws ValidationException When the person was erased or merged into another account
     * @throws LogicException When no action is being dispatched
     * @throws HilosException When the person cannot be read, or the frame cannot be named or queued
     */
    private function askProof(
        int $userId,
        string $acceptKey,
        string $code,
        bool $backupCode,
        bool $cancelReset = false,
        bool $trustDevice = false,
    ): void {
        $this->library->askPersonAgent($userId, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE, new UserSecondFactorProveSignalData(
            userId: $userId,
            code: $code,
            backupCode: $backupCode,
            cancelReset: $cancelReset,
            trustDevice: $trustDevice,
            operation: null,
            replySignal: HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->library->currentActionRequestId(),
            action: $this->library->runningAction(),
            successMessage: null,
        ));
    }

    /**
     * Asks the person's agent to confirm their unfinished enrolment, and stops owing the browser an answer.
     *
     * @param int $userId Person enrolling
     * @param string $acceptKey Accept key the action arrived on
     * @param ?int $authenticatorId Enrolment the profile form names, or null on the way in
     * @param string $code First code from the app
     * @param string $label Name the person gave the app
     * @throws ValidationException When the person was erased or merged into another account
     * @throws LogicException When no action is being dispatched
     * @throws HilosException When the person cannot be read, or the frame cannot be named or queued
     */
    private function askEnrollConfirm(int $userId, string $acceptKey, ?int $authenticatorId, string $code, string $label): void
    {
        $this->library->askPersonAgent(
            $userId,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM,
            new UserSecondFactorEnrollConfirmSignalData(
                userId: $userId,
                authenticatorId: $authenticatorId,
                code: $code,
                label: $label,
                replySignal: HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE,
                acceptKey: $acceptKey,
                requestId: $this->library->currentActionRequestId(),
                action: $this->library->runningAction(),
                successMessage: null,
            ),
        );
    }

    /**
     * Asks the person's agent to cancel a removal, and stops owing the browser an answer.
     *
     * @param int $userId Person whose removal it is
     * @param string $acceptKey Accept key the action arrived on
     * @param ?int $resetId Removal the link names, or null for the one that stands
     * @throws ValidationException When the person was erased or merged into another account
     * @throws LogicException When no action is being dispatched
     * @throws HilosException When the person cannot be read, or the frame cannot be named or queued
     */
    private function askResetCancel(int $userId, string $acceptKey, ?int $resetId): void
    {
        $this->library->askPersonAgent(
            $userId,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL,
            new UserSecondFactorResetCancelSignalData(
                userId: $userId,
                resetId: $resetId,
                replySignal: HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE,
                acceptKey: $acceptKey,
                requestId: $this->library->currentActionRequestId(),
                action: $this->library->runningAction(),
                successMessage: null,
            ),
        );
    }

    /**
     * Mails the lock a wrong app code put, when the answer says this miss put it.
     *
     * @param int $userId Person whose app codes are locked
     * @param ?int $misses Wrong app codes the lock was put on, or null when this miss put none
     * @param ?int $untilSec End of the lock (unix seconds), or null when this miss put none
     */
    private function mailLock(int $userId, ?int $misses, ?int $untilSec): void
    {
        if ($misses !== null && $untilSec !== null) {
            $this->followUp(fn () => SecondFactorLockNotifier::locked($userId, $misses, $untilSec), 'lock letter', $userId);
        }
    }

    /**
     * Runs one thing that follows an answer of the person's agent, logging its failure instead of raising it.
     *
     * @param Closure(): void $step What follows - a frame to the holder, a letter
     * @param string $what Which follow-up, for the log line
     * @param int $userId Person it was for, for the log line
     */
    private function followUp(Closure $step, string $what, int $userId): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            Logger::error("Second factor of #{$userId}: the {$what} failed: {$e->getMessage()}");
        }
    }

    /**
     * Reads whose sign-in waits on this browser, refusing a wait on another screen.
     *
     * A wait that ran out is not refused but answered: the holder is asked to let it go and to
     * send the tabs back to the address field with the reason, and null tells the caller the
     * answer is on its way.
     *
     * @param ActingSession $acting Browser that submitted
     * @param list<string> $modes Screens the submit belongs to ({@see SecondFactorPendingMode} values)
     * @return ?int The person whose sign-in waits, or null when the wait ran out and was answered
     * @throws ValidationException When the browser waits on none of those screens
     * @throws HilosException When the lookup or the frame fails
     */
    private function waitingUser(ActingSession $acting, array $modes): ?int
    {
        $session = Hilos::$db->sessions->findByToken($acting->sessionToken);
        $userId = $session?->pendingSecondFactorUserId;
        $until = $session?->pendingSecondFactorUntil;
        if ($userId === null || $until === null || !in_array($session->pendingSecondFactorMode, $modes, true)) {
            throw new ValidationException(SecondFactorMessages::STEP_EXPIRED);
        }

        if ($until <= TimeHelper::getSqlDateTime()) {
            $this->library->announceSecondFactorCanceled(
                $acting,
                AuthFlowOutcome::rejectTo(
                    AuthFlowOutcome::CODE_SECOND_FACTOR_EXPIRED,
                    AuthFlowStep::IDENTIFIER,
                    AuthFlowIntent::LOGIN,
                    SecondFactorMessages::STEP_EXPIRED,
                ),
                AuthFlowOutcome::CODE_SECOND_FACTOR_EXPIRED,
            );

            return null;
        }

        return $userId;
    }
}
