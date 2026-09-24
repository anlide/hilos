<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

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
use Hilos\Auth\SecondFactor\SecondFactorMessages;
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorResetNotifier;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\SecondFactorResetWait;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\View\Item\SecondFactor;
use Hilos\Database\View\Item\SecondFactorReset;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Random\RandomException;

/**
 * The second factor's commands: the code step of a sign-in, enrolment on the way in, and the
 * delayed removal (HIL-494).
 *
 * A sign-in reaches these already proven and held: the session holder
 * ({@see AbstractSessionsLibraryAgent}) wrote the wait on the session row, naming the person
 * and the screen. Every command here reads whose sign-in it is off that wait - never off the
 * browser - and refuses a wait on another screen with the one sentence that sends the person
 * back to start. A wait that ran out is answered with the address field and its reason, and
 * the holder lets it go.
 *
 * The codes are checked here, where the authenticators are owned; the wait itself is written
 * only by the holder, which this group asks by frame: a wrong code counted, an enrolment
 * confirmed, a wait to let go, a sign-in to let through.
 *
 * The profile half works on a signed-in person, whose id comes off the session: every action
 * but the first enrolment, the wait and the removal starts with a code, because a stolen live
 * session must not strip or copy the factor. After every write the person's section is fanned
 * to their group, whichever tab or browser made it.
 */
final class SecondFactorCommands extends AbstractLibraryCommands
{
    /** Hash the cancel link's token is kept under. */
    private const string TOKEN_HASH = 'sha256';

    /** Bytes of a cancel link's token. */
    private const int TOKEN_BYTES = 32;

    /** Longest name an authenticator may have, as the column holds it. */
    private const int LABEL_MAX = 64;

    /**
     * Checks the code of a sign-in held on its second factor and lets the sign-in through.
     *
     * A code from any confirmed authenticator of the person passes, once: the step it matched
     * is taken by a conditional write, so the same code does not pass twice. A backup code is
     * burned the same way. A right code also ends a removal of the factor that stands - whoever
     * shows the factor has not lost it. A wrong code is counted on the session by the holder.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ConfirmSecondFactorActionDTO $dto Code, its kind, and whether to trust the browser
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When no wait stands on the code step, or the code matches nothing
     * @throws HilosException When a lookup, a write or a frame fails
     */
    public function confirm(string $acceptKey, ConfirmSecondFactorActionDTO $dto): void
    {
        $acting = $this->acting($acceptKey);
        $userId = $this->waitingUser($acting, [SecondFactorPendingMode::VERIFY, SecondFactorPendingMode::SETUP_DONE]);
        if ($userId === null) {
            return;
        }

        $proven = $dto->backupCode
            ? $this->spendBackupCode($userId, $dto->code)
            : $this->acceptAppCode(Hilos::$db->secondFactors->confirmedOf($userId), $dto->code);
        if (!$proven) {
            $this->library->announceSecondFactorMissed($acting);

            throw new ValidationException(SecondFactorMessages::INVALID_CODE);
        }

        $this->cancelReset(Hilos::$db->secondFactorResets->liveOf($userId));
        $this->publishState($userId);
        $this->library->grantSession($acting, $userId, secondFactorProven: true, trustDevice: $dto->trustDevice);
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
     * Confirms the enrolment on the way in with its first code, and issues the backup codes.
     *
     * The codes are answered once, here, and the surface moves to the screen that shows them;
     * the person is let in by the Continue under them ({@see setupFinish()}).
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param SecondFactorSetupConfirmActionDTO $dto First code and the name of the app
     * @return ?AuthFlowOutcome The codes screen, or null when the holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When no wait stands on the enrolment, no enrolment was started, or the code is wrong
     * @throws RandomException When the backup codes cannot be drawn
     * @throws HilosException When a lookup, a write or a frame fails
     */
    public function setupConfirm(string $acceptKey, SecondFactorSetupConfirmActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);
        $userId = $this->waitingUser($acting, [SecondFactorPendingMode::SETUP]);
        if ($userId === null) {
            return null;
        }

        $codes = $this->confirmEnrolment($userId, $dto->code, $dto->label, $acting);
        $this->library->announceSecondFactorSetupProven($acting);
        $this->publishState($userId);

        return AuthFlowOutcome::moveToSecondFactor(
            AuthFlowStep::SECOND_FACTOR_CODES,
            new SecondFactorStepData(SecondFactorPolicy::current()->trustDeviceDays(), null, backupCodes: $codes),
            null,
        );
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
     * @param SecondFactorResetCancelLinkActionDTO $dto Token the link carried
     * @return AuthFlowOutcome Success; the relay screen shows it in place
     * @throws ValidationException When the link names no standing removal
     * @throws HilosException When a lookup, a write or the announcement fails
     */
    public function resetCancelLink(SecondFactorResetCancelLinkActionDTO $dto): AuthFlowOutcome
    {
        $reset = $dto->token === ''
            ? null
            : Hilos::$db->secondFactorResets->findLiveByTokenHash(hash(self::TOKEN_HASH, $dto->token));
        if ($reset === null || !$this->cancelReset($reset)) {
            throw new ValidationException(SecondFactorMessages::LINK_DEAD);
        }
        $this->publishState($reset->userId);

        return AuthFlowOutcome::moveTo(AuthFlowStep::DONE, AuthFlowIntent::LOGIN);
    }

    /**
     * Opens a delayed removal of a person's second factor and announces it.
     *
     * Shared by the code step and the profile. The wait is the person's own, taken as it stands
     * at the moment of asking, and the first announcement carries the cancel link.
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
        $reset = Hilos::$db->secondFactorResets->actions->request(
            $userId,
            date('Y-m-d H:i:s', $effectiveAtSec),
            hash(self::TOKEN_HASH, $token),
        );
        SecondFactorResetNotifier::requested($userId, $token, $effectiveAtSec);

        return $reset;
    }

    /**
     * Cancels a standing removal and announces it, if it still stands.
     *
     * @param ?SecondFactorReset $reset Removal to cancel, or null when none stands
     * @return bool True when this call canceled it
     * @throws HilosException When the write or the announcement fails
     */
    public function cancelReset(?SecondFactorReset $reset): bool
    {
        if ($reset === null || !$reset->actions->cancel()) {
            return false;
        }

        SecondFactorResetNotifier::canceled($reset->userId);

        return true;
    }

    /**
     * Opens an enrolment for a person: a fresh secret on an unconfirmed authenticator.
     *
     * @param int $userId Person enrolling
     * @return array{0: int, 1: string, 2: string} The unconfirmed authenticator, its base32 secret and otpauth address
     * @throws RandomException When the secret cannot be drawn
     * @throws HilosException When a lookup or the write fails
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
            OtpAuthUri::build(Hilos::$env[EnvConstants::HILOS_WEBAUTHN_RP_NAME]->string(), $account, $secret),
        ];
    }

    /**
     * Confirms a person's unfinished enrolment with its first code.
     *
     * The first authenticator of a person comes with a set of backup codes; a further one does
     * not - the set the person has stays theirs.
     *
     * @param int $userId Person enrolling
     * @param string $code First code from the app
     * @param string $label Name the person gave the app, or empty for the default
     * @param ?ActingSession $acting Browser whose wrong code the holder counts, or null when no sign-in waits
     * @return ?list<string> Backup codes in display form when a set was issued, or null
     * @throws ValidationException When no enrolment was started in time, or the code is wrong
     * @throws RandomException When the backup codes cannot be drawn
     * @throws HilosException When a lookup, a write or a frame fails
     */
    public function confirmEnrolment(int $userId, string $code, string $label, ?ActingSession $acting): ?array
    {
        $pending = Hilos::$db->secondFactors->unconfirmedOf($userId);
        $ttl = Hilos::$env[EnvConstants::HILOS_VERIFICATION_TTL_SEC]->int();
        if ($pending === null || strtotime($pending->createdAt) < time() - $ttl) {
            throw new ValidationException(SecondFactorMessages::SETUP_EXPIRED);
        }

        if (!$this->acceptAppCode([$pending], $code)) {
            if ($acting !== null) {
                $this->library->announceSecondFactorMissed($acting);
            }

            throw new ValidationException(SecondFactorMessages::INVALID_CODE);
        }

        $first = Hilos::$db->secondFactors->confirmedOf($userId) === [];
        $name = mb_substr(trim($label), 0, self::LABEL_MAX);
        $pending->actions->confirm($name === '' ? SecondFactorMessages::DEFAULT_LABEL : $name);
        if (!$first) {
            return null;
        }

        return $this->issueBackupCodes($userId);
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
     * Checks a code from an app against authenticators, taking its step on the one it matched.
     *
     * @param list<SecondFactor> $factors Authenticators to check against
     * @param string $code Code as typed
     * @return bool True when the code matched one and its step was not taken before
     * @throws HilosException When a lookup or the write fails
     */
    public function acceptAppCode(array $factors, string $code): bool
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
     * Burns a backup code of a person, if the typed code names an unused one.
     *
     * @param int $userId Person
     * @param string $code Code as typed
     * @return bool True when this call burned it
     * @throws HilosException When a lookup or the write fails
     */
    public function spendBackupCode(int $userId, string $code): bool
    {
        $row = Hilos::$db->secondFactorBackupCodes->findUnused($userId, BackupCodeGenerator::normalize($code));

        return $row !== null && $row->actions->spend();
    }

    /**
     * Starts connecting an authenticator app from the profile, answering the secret once.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorEnrollStartActionDTO $dto Proof, when an app is already connected
     * @return SecondFactorProfileReplyDTO The enrolment, its secret and otpauth address
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the proof is missing or wrong
     * @throws RandomException When the secret cannot be drawn
     * @throws HilosException When a lookup or a write fails
     */
    public function profileEnrollStart(string $acceptKey, ProfileSecondFactorEnrollStartActionDTO $dto): SecondFactorProfileReplyDTO
    {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        if (Hilos::$db->secondFactors->confirmedOf($userId) !== []) {
            $this->assertProof($userId, (string)$dto->proofCode, $dto->proofBackup);
        }

        [$id, $secret, $uri] = $this->startEnrolment($userId);

        return new SecondFactorProfileReplyDTO(authenticatorId: $id, secret: $secret, otpauthUri: $uri);
    }

    /**
     * Confirms an app being connected from the profile with its first code.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorEnrollConfirmActionDTO $dto Enrolment, first code and name
     * @return SecondFactorProfileReplyDTO The backup codes when this is the first app, else nothing
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the enrolment ran out or the code is wrong
     * @throws RandomException When the backup codes cannot be drawn
     * @throws HilosException When a lookup or a write fails
     */
    public function profileEnrollConfirm(
        string $acceptKey,
        ProfileSecondFactorEnrollConfirmActionDTO $dto,
    ): SecondFactorProfileReplyDTO {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        if (Hilos::$db->secondFactors->unconfirmedOf($userId)?->id !== $dto->authenticatorId) {
            throw new ValidationException(SecondFactorMessages::SETUP_EXPIRED);
        }

        $codes = $this->confirmEnrolment($userId, $dto->code, $dto->label, null);
        $this->publishState($userId);

        return new SecondFactorProfileReplyDTO(backupCodes: $codes);
    }

    /**
     * Disconnects an app from the profile; the last one takes the whole second factor with it.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorRemoveActionDTO $dto App and the proof
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the app is not the person's, the administrator requires the last one, or the proof is wrong
     * @throws HilosException When a lookup, a write or a frame fails
     */
    public function profileRemove(string $acceptKey, ProfileSecondFactorRemoveActionDTO $dto): void
    {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        $factors = Hilos::$db->secondFactors->confirmedOf($userId);
        $target = null;
        foreach ($factors as $factor) {
            if ($factor->id === $dto->authenticatorId) {
                $target = $factor;
            }
        }
        if ($target === null) {
            throw new ValidationException(SecondFactorMessages::NOT_CONNECTED);
        }

        $last = count($factors) === 1;
        if ($last && SecondFactorPolicy::current()->requiresFor($userId)) {
            throw new ValidationException(SecondFactorMessages::REQUIRED);
        }
        $this->assertProof($userId, $dto->proofCode, $dto->proofBackup);

        if ($last) {
            $reset = Hilos::$db->secondFactorResets->liveOf($userId);
            $reset?->actions->cancel();
            $this->switchOff($userId);
        } else {
            $target->actions->delete();
        }
        $this->publishState($userId);
    }

    /**
     * Shows the backup codes, used ones included, after a code.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorCodesShowActionDTO $dto The proof
     * @return SecondFactorProfileReplyDTO The set as the screen lists it
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the proof is wrong
     * @throws HilosException When a lookup or a write fails
     */
    public function profileCodesShow(string $acceptKey, ProfileSecondFactorCodesShowActionDTO $dto): SecondFactorProfileReplyDTO
    {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        $this->assertProof($userId, $dto->proofCode, $dto->proofBackup);
        $this->publishState($userId);

        return new SecondFactorProfileReplyDTO(codes: Hilos::$db->secondFactorBackupCodes->entriesOf($userId));
    }

    /**
     * Issues a new set of backup codes after a code; the old set dies.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorCodesRenewActionDTO $dto The proof
     * @return SecondFactorProfileReplyDTO The new codes
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the proof is wrong
     * @throws RandomException When the codes cannot be drawn
     * @throws HilosException When a lookup or a write fails
     */
    public function profileCodesRenew(string $acceptKey, ProfileSecondFactorCodesRenewActionDTO $dto): SecondFactorProfileReplyDTO
    {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        $this->assertProof($userId, $dto->proofCode, $dto->proofBackup);
        $codes = $this->issueBackupCodes($userId);
        $this->publishState($userId);

        return new SecondFactorProfileReplyDTO(backupCodes: $codes);
    }

    /**
     * Chooses the person's removal wait: longer at once, shorter after the wait in force.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSecondFactorResetWaitSetActionDTO $dto Wait asked for
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When the wait is outside the administrator's bounds
     * @throws HilosException When a lookup or the write fails
     */
    public function profileResetWaitSet(string $acceptKey, ProfileSecondFactorResetWaitSetActionDTO $dto): void
    {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        $policy = SecondFactorPolicy::current();
        $min = max($policy->resetWaitMinDays, SecondFactorSettings::RESET_WAIT_FLOOR_DAYS);
        if ($dto->days < $min || $dto->days > $policy->resetWaitMaxDays) {
            throw new ValidationException(sprintf(SecondFactorMessages::WAIT_OUT_OF_BOUNDS, $min, $policy->resetWaitMaxDays));
        }

        $wait = SecondFactorResetWait::of($userId)->withRequested($dto->days, $policy, time());
        Hilos::$db->secondFactorSettings->actions->setResetWait(
            $userId,
            $wait->days,
            $wait->pendingDays,
            $wait->pendingFromSec === null ? null : date('Y-m-d H:i:s', $wait->pendingFromSec),
        );
        $this->publishState($userId);
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
     * Cancels the removal that stands, from the profile.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws HilosException When a lookup, the write or the announcement fails
     */
    public function profileResetCancel(string $acceptKey): void
    {
        $userId = (int)$this->actingUser($acceptKey)->userId;
        $this->cancelReset(Hilos::$db->secondFactorResets->liveOf($userId));
        $this->publishState($userId);
    }

    /**
     * Takes a person's second factor out whole: apps, backup codes, and what the holder keeps for it.
     *
     * Shared by the last app disconnected and the removal carried out. The person's own wait
     * stays - it is a choice about the next factor as much as this one.
     *
     * @param int $userId Person
     * @throws HilosException When a delete or the frame fails
     */
    public function switchOff(int $userId): void
    {
        Hilos::$db->secondFactors->actions->deleteForUser($userId);
        Hilos::$db->secondFactorBackupCodes->actions->deleteForUser($userId);
        $this->library->announceSecondFactorOff($userId);
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
     * Refuses a profile action whose code proves nothing.
     *
     * @param int $userId Person
     * @param string $code Code as typed
     * @param bool $backupCode Whether it is a backup code
     * @throws ValidationException When the code matches nothing
     * @throws HilosException When a lookup or the write fails
     */
    private function assertProof(int $userId, string $code, bool $backupCode): void
    {
        $proven = $backupCode
            ? $this->spendBackupCode($userId, $code)
            : $this->acceptAppCode(Hilos::$db->secondFactors->confirmedOf($userId), $code);
        if (!$proven) {
            throw new ValidationException(SecondFactorMessages::INVALID_CODE);
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
