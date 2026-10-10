<?php

declare(strict_types=1);

namespace Hilos\Users\Agent;

use Closure;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\SecondFactor\SecondFactorPersonEdits;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentIndexException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Item\UserActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\View\Collection\Identities;
use Hilos\Database\View\Item\User;
use Hilos\Database\View\Item\UserRename;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\AddressablePerson;
use Hilos\Users\DTO\UserAddressVerifyDoneSignalData;
use Hilos\Users\DTO\UserAddressVerifySignalData;
use Hilos\Users\DTO\UserAdminCommandDoneSignalData;
use Hilos\Users\DTO\UserAdminCommandSignalData;
use Hilos\Users\DTO\UserAdminWriteDoneSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteDoneSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserEmailChangeDoneSignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkDoneSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserPasskeyUseDoneSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasswordChangeDoneSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserPasswordRehashDoneSignalData;
use Hilos\Users\DTO\UserPasswordRehashSignalData;
use Hilos\Users\DTO\UserPasswordResetDoneSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
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
use Hilos\WiringRefusal;
use Throwable;

/**
 * Agent for one person. Its claims name the person's row and child sets; it keeps no copy of them.
 *
 * It writes the ordinary edits of the person's row - the name, the admin flag, the block (HIL-1404) -
 * and every edit of the person's ways of signing in and passkeys, sign-in included (HIL-1405): a
 * fresh password hash, a password verified by a letter, a passkey's counter and last use, a new
 * password from recovery or from the profile, an address moved, a method unlinked. It writes the
 * person's second factor too (HIL-1406): an app confirmed or disconnected, a code that proves the
 * person - a step taken, a backup code burned, a wrong code counted and the lock it puts or an
 * operator lifts - the removal wait, and a removal canceled, carried out or marked reminded
 * ({@see SecondFactorPersonEdits}). Each comes as a frame from the coordinator that judged it: the
 * name, the ways in and the second factor from {@see AbstractUsersLibraryAgent}, the two flags from
 * {@see AbstractSessionsLibraryAgent}. The
 * agent writes and always answers - a refusal included, because the coordinator continues only on
 * the answer and something is waiting on it - and the coordinator does what follows the write.
 * Creating a way in, an enrolment, a set of backup codes or a removal stays with the libraries that
 * create it. When the person is erased, or folded
 * into someone else, the agent stops itself.
 */
abstract class AbstractUserAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USER;

    /**
     * The edits of one person, each addressed by the person's id; the answers go back to the
     * coordinator under the name each frame carries.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_USER_RENAME => [
            AgentSignalConfigKey::INDEX_FIELD => UserRenameSignalData::userId,
            AgentSignalConfigKey::DTO => UserRenameSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_ADMIN_WRITE => [
            AgentSignalConfigKey::INDEX_FIELD => UserAdminWriteSignalData::userId,
            AgentSignalConfigKey::DTO => UserAdminWriteSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => [
            AgentSignalConfigKey::INDEX_FIELD => UserAdminCommandSignalData::userId,
            AgentSignalConfigKey::DTO => UserAdminCommandSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_BLOCK_WRITE => [
            AgentSignalConfigKey::INDEX_FIELD => UserBlockWriteSignalData::userId,
            AgentSignalConfigKey::DTO => UserBlockWriteSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PASSWORD_REHASH => [
            AgentSignalConfigKey::INDEX_FIELD => UserPasswordRehashSignalData::userId,
            AgentSignalConfigKey::DTO => UserPasswordRehashSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY => [
            AgentSignalConfigKey::INDEX_FIELD => UserAddressVerifySignalData::userId,
            AgentSignalConfigKey::DTO => UserAddressVerifySignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PASSKEY_USE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPasskeyUseSignalData::userId,
            AgentSignalConfigKey::DTO => UserPasskeyUseSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PASSWORD_RESET => [
            AgentSignalConfigKey::INDEX_FIELD => UserPasswordResetSignalData::userId,
            AgentSignalConfigKey::DTO => UserPasswordResetSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPasswordChangeSignalData::userId,
            AgentSignalConfigKey::DTO => UserPasswordChangeSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_EMAIL_CHANGE => [
            AgentSignalConfigKey::INDEX_FIELD => UserEmailChangeSignalData::userId,
            AgentSignalConfigKey::DTO => UserEmailChangeSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK => [
            AgentSignalConfigKey::INDEX_FIELD => UserIdentityUnlinkSignalData::userId,
            AgentSignalConfigKey::DTO => UserIdentityUnlinkSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorProveSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorProveSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorEnrollConfirmSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorEnrollConfirmSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorRemoveSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorRemoveSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorResetCancelSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorResetCancelSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorWaitWriteSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorWaitWriteSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorResetDueSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorResetDueSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorResetRemindSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorResetRemindSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorUnlockSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorUnlockSignalData::class,
        ],
    ];

    /** @var array<string, list<TruthSourceOperation>> The person's row, excluding creation and removal. */
    public const array OWNS_DB_ROWS = [
        HilosDbContext::users => [TruthSourceOperation::Update],
    ];

    /**
     * @var array<string, list<TruthSourceOperation>> The person's borrowed child sets, and the rename
     *     journal of the person, which the agent adds a row to in the transaction that writes the name.
     *     The ways of signing in and the passkeys are written here since HIL-1405, and the second factor
     *     since HIL-1406; their creation is the libraries'. One row is born here too: the person's
     *     second-factor settings (`hilos_second_factor_setting`, keyed by the person), which the
     *     owner's first edit of it brings into being - a wait chosen, a wrong code counted - so the
     *     agent claims that set with adding and editing (owner's decision, 2026-10-09). It is a
     *     one-to-one extension of the person's own row, not a new member of a set.
     */
    public const array OWNS_DB_SET = [
        HilosDbContext::identities => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::passkeyCredentials => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactors => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorBackupCodes => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorResets => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorSettings => [TruthSourceOperation::Add, TruthSourceOperation::Update],
        HilosDbContext::secondFactorTrusts => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::stepUps => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::accountDeletions => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::userPhotos => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::notifications => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::notificationPreferences => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::pushSubscriptions => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::userRenames => [TruthSourceOperation::Add],
    ];

    private int $userId;

    /** The writes of the person's second factor, built on first use. */
    private ?SecondFactorPersonEdits $secondFactorEdits = null;

    /**
     * @param string $agentIndex Person id from the agent address
     * @throws AgentIndexRequiredException When the address omits the id
     * @throws InvalidAgentIndexException When the id is not a positive integer
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('User agent requires a person id');
        }

        $userId = ctype_digit($agentIndex)
            ? filter_var($agentIndex, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        // The master keys the agent by the address as sent. An alternate spelling of one id
        // would split that key from the worker's normalized id and duplicate its set claim.
        if ($userId === false || (string)$userId !== $agentIndex) {
            throw new InvalidAgentIndexException('User agent requires a positive integer person id');
        }

        $this->userId = $userId;
        $this->agentIndex = $agentIndex;
    }

    /**
     * The words a refused rename has always used, shared by this agent and the library that
     * answers before the hop.
     *
     * A missing person is named by id. Anything else the row refuses - a folded account, a name
     * the row will not hold - keeps the sentence the card already shows.
     *
     * @param int $userId Person the rename was about
     * @param ValidationException $failure What the check or the row refused
     * @return string Sentence the waiting card or window reads
     */
    public static function renameRefusalWords(int $userId, ValidationException $failure): string
    {
        if ($failure instanceof ItemNotFoundForUpdateException) {
            return "User #{$userId} not found";
        }

        return 'Failed to update user: ' . $failure->getMessage();
    }

    /**
     * @param string $collection Collection whose row claim is being resolved
     * @return list<string> The person's row key
     */
    public function ownedDbRowKeys(string $collection): array
    {
        return [(string)$this->userId];
    }

    /**
     * @param string $collection Collection whose set claim is being resolved
     * @return string The person's root set key
     */
    public function ownedDbSetKey(string $collection): string
    {
        return (string)$this->userId;
    }

    /**
     * Writes one edit of the person and answers the coordinator that asked for it.
     *
     * A frame naming another person never reached the right agent, and is refused as such rather
     * than written: the claims of this agent cover its own person alone.
     *
     * A frame of the second factor is answered by {@see SecondFactorPersonEdits}, whatever came of
     * it: the browser action, the sweep or the operator's command waits on the answer (HIL-1406).
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one of this agent's frames
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws AgentException When the frame names another person
     * @throws InvalidArgumentException When the answer frame cannot be named or queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_USER_RENAME:
                $rename = $data->data;
                if (!$rename instanceof UserRenameSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserRenameSignalData::class, $rename);
                }

                $this->refuseAnotherPerson($rename->userId, $name);
                $this->handleRename($rename);

                return;

            case HilosSignalConstants::HILOS_USER_ADMIN_WRITE:
                $adminWrite = $data->data;
                if (!$adminWrite instanceof UserAdminWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserAdminWriteSignalData::class, $adminWrite);
                }

                $this->refuseAnotherPerson($adminWrite->userId, $name);
                $this->sendToAgent($adminWrite->replySignal, UserAdminWriteDoneSignalData::to(
                    $adminWrite,
                    $this->flagRefusal(fn () => $this->writeAdminFlag($adminWrite->admin), 'Admin rights'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_ADMIN_COMMAND:
                $adminCommand = $data->data;
                if (!$adminCommand instanceof UserAdminCommandSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserAdminCommandSignalData::class, $adminCommand);
                }

                $this->refuseAnotherPerson($adminCommand->userId, $name);
                $this->sendToAgent($adminCommand->replySignal, UserAdminCommandDoneSignalData::to(
                    $adminCommand,
                    $this->flagRefusal(fn () => $this->writeAdminFlag($adminCommand->admin), "Admin rights by {$adminCommand->command}"),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_BLOCK_WRITE:
                $blockWrite = $data->data;
                if (!$blockWrite instanceof UserBlockWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserBlockWriteSignalData::class, $blockWrite);
                }

                $this->refuseAnotherPerson($blockWrite->userId, $name);
                $this->sendToAgent($blockWrite->replySignal, UserBlockWriteDoneSignalData::to(
                    $blockWrite,
                    $this->flagRefusal(fn () => $this->writeBlockFlag($blockWrite->block), 'Account block'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_PASSWORD_REHASH:
                $rehash = $data->data;
                if (!$rehash instanceof UserPasswordRehashSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPasswordRehashSignalData::class, $rehash);
                }

                $this->refuseAnotherPerson($rehash->userId, $name);
                $this->sendToAgent($rehash->replySignal, UserPasswordRehashDoneSignalData::to(
                    $rehash,
                    $this->flagRefusal(fn () => $this->writePasswordHash($rehash->identityId, $rehash->passwordHash), 'Password rehash'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY:
                $addressVerify = $data->data;
                if (!$addressVerify instanceof UserAddressVerifySignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserAddressVerifySignalData::class, $addressVerify);
                }

                $this->refuseAnotherPerson($addressVerify->userId, $name);
                $this->sendToAgent($addressVerify->replySignal, UserAddressVerifyDoneSignalData::to(
                    $addressVerify,
                    $this->flagRefusal(fn () => $this->markAddressVerified($addressVerify->identityId), 'Address verification'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_PASSKEY_USE:
                $passkeyUse = $data->data;
                if (!$passkeyUse instanceof UserPasskeyUseSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPasskeyUseSignalData::class, $passkeyUse);
                }

                $this->refuseAnotherPerson($passkeyUse->userId, $name);
                $this->sendToAgent($passkeyUse->replySignal, UserPasskeyUseDoneSignalData::to(
                    $passkeyUse,
                    $this->flagRefusal(fn () => $this->recordPasskeyUse($passkeyUse->passkeyId, $passkeyUse->signCount), 'Passkey use'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_PASSWORD_RESET:
                $reset = $data->data;
                if (!$reset instanceof UserPasswordResetSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPasswordResetSignalData::class, $reset);
                }

                $this->refuseAnotherPerson($reset->userId, $name);
                $this->sendToAgent($reset->replySignal, UserPasswordResetDoneSignalData::to(
                    $reset,
                    $this->flagRefusal(fn () => $this->writePasswordHash($reset->identityId, $reset->passwordHash), 'Password recovery'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE:
                $change = $data->data;
                if (!$change instanceof UserPasswordChangeSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPasswordChangeSignalData::class, $change);
                }

                $this->refuseAnotherPerson($change->userId, $name);
                $this->sendToAgent($change->replySignal, UserPasswordChangeDoneSignalData::to(
                    $change,
                    $this->flagRefusal(fn () => $this->writePasswordHash($change->identityId, $change->passwordHash), 'Password change'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_EMAIL_CHANGE:
                $emailChange = $data->data;
                if (!$emailChange instanceof UserEmailChangeSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserEmailChangeSignalData::class, $emailChange);
                }

                $this->refuseAnotherPerson($emailChange->userId, $name);
                $this->sendToAgent($emailChange->replySignal, UserEmailChangeDoneSignalData::to(
                    $emailChange,
                    $this->flagRefusal(fn () => $this->moveEmail($emailChange->from, $emailChange->to), 'Email change'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK:
                $unlink = $data->data;
                if (!$unlink instanceof UserIdentityUnlinkSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserIdentityUnlinkSignalData::class, $unlink);
                }

                $this->refuseAnotherPerson($unlink->userId, $name);
                $this->sendToAgent($unlink->replySignal, UserIdentityUnlinkDoneSignalData::to(
                    $unlink,
                    $this->flagRefusal(fn () => $this->unlinkIdentity($unlink->identityId), 'Sign-in method unlink'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE:
                $prove = $data->data;
                if (!$prove instanceof UserSecondFactorProveSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorProveSignalData::class, $prove);
                }

                $this->refuseAnotherPerson($prove->userId, $name);
                $this->sendToAgent($prove->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->prove($prove),
                    fn (ActionRefusal $refusal) => UserSecondFactorProveDoneSignalData::refused($prove, $refusal),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM:
                $enrollConfirm = $data->data;
                if (!$enrollConfirm instanceof UserSecondFactorEnrollConfirmSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorEnrollConfirmSignalData::class, $enrollConfirm);
                }

                $this->refuseAnotherPerson($enrollConfirm->userId, $name);
                $this->sendToAgent($enrollConfirm->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->enrollConfirm($enrollConfirm),
                    fn (ActionRefusal $refusal) => UserSecondFactorEnrollConfirmDoneSignalData::refused($enrollConfirm, $refusal),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE:
                $removal = $data->data;
                if (!$removal instanceof UserSecondFactorRemoveSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorRemoveSignalData::class, $removal);
                }

                $this->refuseAnotherPerson($removal->userId, $name);
                $this->sendToAgent($removal->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->remove($removal),
                    fn (ActionRefusal $refusal) => UserSecondFactorRemoveDoneSignalData::refused($removal, $refusal),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL:
                $resetCancel = $data->data;
                if (!$resetCancel instanceof UserSecondFactorResetCancelSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorResetCancelSignalData::class, $resetCancel);
                }

                $this->refuseAnotherPerson($resetCancel->userId, $name);
                $this->sendToAgent($resetCancel->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->cancelReset($resetCancel),
                    fn (ActionRefusal $refusal) => UserSecondFactorResetCancelDoneSignalData::refused($resetCancel, $refusal),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE:
                $waitWrite = $data->data;
                if (!$waitWrite instanceof UserSecondFactorWaitWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorWaitWriteSignalData::class, $waitWrite);
                }

                $this->refuseAnotherPerson($waitWrite->userId, $name);
                $this->sendToAgent($waitWrite->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->writeWait($waitWrite),
                    fn (ActionRefusal $refusal) => UserSecondFactorWaitWriteDoneSignalData::refused($waitWrite, $refusal),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE:
                $resetDue = $data->data;
                if (!$resetDue instanceof UserSecondFactorResetDueSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorResetDueSignalData::class, $resetDue);
                }

                $this->refuseAnotherPerson($resetDue->userId, $name);
                $this->sendToAgent($resetDue->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->carryOutReset($resetDue),
                    fn (ActionRefusal $refusal) => UserSecondFactorResetDueDoneSignalData::refused($resetDue, $refusal),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND:
                $resetRemind = $data->data;
                if (!$resetRemind instanceof UserSecondFactorResetRemindSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorResetRemindSignalData::class, $resetRemind);
                }

                $this->refuseAnotherPerson($resetRemind->userId, $name);
                $this->sendToAgent($resetRemind->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->remind($resetRemind),
                    fn (ActionRefusal $refusal) => UserSecondFactorResetRemindDoneSignalData::refused($resetRemind, $refusal),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK:
                $unlock = $data->data;
                if (!$unlock instanceof UserSecondFactorUnlockSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorUnlockSignalData::class, $unlock);
                }

                $this->refuseAnotherPerson($unlock->userId, $name);
                $this->sendToAgent($unlock->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->unlock($unlock),
                    fn (ActionRefusal $refusal) => UserSecondFactorUnlockDoneSignalData::refused($unlock, $refusal),
                ));

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Stops when this person's own row is deleted, which is what an erasure commits.
     *
     * A project subclass that overrides this calls parent, so the stop still happens. Another
     * person's row, a child row, a merge row and an update of this row are ignored: one fact
     * per person is enough, and the survivor of a merge is not this agent.
     *
     * @param DbSyncDeletedSignalData $data Deleted row
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused)
     */
    public function onSignalDbSyncDeleted(DbSyncDeletedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === HilosDbContext::users && $this->isThisPerson($data->idString)) {
            $this->selfStop();
        }
    }

    /**
     * Stops when a merge row appears for this person, which is what folding them in commits.
     *
     * A project subclass that overrides this calls parent, so the stop still happens. The merge
     * row is keyed by the folded account, so a row naming this person as the survivor does not
     * stop this agent. Every other collection is ignored.
     *
     * @param DbSyncCreatedSignalData $data Created row
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused)
     */
    public function onSignalDbSyncCreated(DbSyncCreatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === HilosDbContext::userMerges && $this->isThisPerson($data->idString)) {
            $this->selfStop();
        }
    }

    /** WorkerManager releases the claims after this hook returns; the agent holds no other state. */
    public function onStop(): void
    {
    }

    /**
     * Renames the person and records the rename in the framework's journal (HIL-1195).
     *
     * The name and the journal row are one transaction: a rename the journal does not know of did
     * not happen. A name that is already the person's, once trimmed, writes nothing and answers
     * null. An account folded into another one is refused before anything is read of it
     * (HIL-1292): its name is a tombstone's. What follows the commit - the notice to the person,
     * the project's hook - is the users library's, on the answer.
     *
     * Not final: the framework's test stands replace it to pin the route apart from the table.
     *
     * @param string $name Name to give; trimmed and held to the frame of {@see UserActions::rename()}
     * @param ?int $renamedByUserId Person who did the rename - the renamed person's own id when they renamed
     *     themselves - or null when the author is not a person
     * @return ?UserRename Journal row of this rename, or null when the name was already the person's
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one, or the name is empty, too short or too long
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person or the merges cannot be loaded
     * @throws HilosException When the name, the journal row or the transaction cannot be written
     */
    protected function renamePerson(string $name, ?int $renamedByUserId): ?UserRename
    {
        $user = $this->personToWrite();
        $oldName = $user->name;
        if (trim($name) === $oldName) {
            return null;
        }

        Database::transactionStart();
        try {
            $user->actions->rename($name);
            $rename = Hilos::$db->userRenames->actions->add($this->userId, $renamedByUserId, $oldName, $user->name);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            $this->rollBack();

            throw $failure;
        }

        return $rename;
    }

    /**
     * Writes the person's admin flag, and nothing else.
     *
     * Telling the person's browsers is the sessions library's, once this answers. An account
     * folded into another one is refused, both ways, before the write (HIL-1199): its rights belong
     * to the account it became. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param bool $admin New admin flag
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person or the merges cannot be loaded
     * @throws HilosException On database failure while reading or writing the flag
     */
    protected function writeAdminFlag(bool $admin): void
    {
        $this->personToWrite()->actions->setAdmin($admin);
    }

    /**
     * Writes the person's block flag; the sessions library ends the sessions once this answers.
     *
     * The same shape as {@see self::writeAdminFlag()}: a folded account is refused, both ways,
     * before the write - the merge closed its sign-in with the flag, and the flag is not an
     * administrator's to reopen. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param bool $block New block flag
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person or the merges cannot be loaded
     * @throws HilosException On database or truth-source failure while reading or writing the flag
     */
    protected function writeBlockFlag(bool $block): void
    {
        $this->personToWrite()->actions->setBlock($block);
    }

    /**
     * Stores a password hash on the person's password row (HIL-1405).
     *
     * One write for three asks - a rehash on sign-in, a recovery, a change in the profile - because
     * the write is the same and only what the users library does after it differs. The hash was
     * minted where the password arrived; the password itself never reaches this agent. The row is
     * the one the library checked, named by id: one that is gone, is not a password, or is not this
     * person's is refused rather than written somewhere else. Not final, for the reason
     * {@see self::renamePerson()} gives.
     *
     * @param int $identityId Password row to write
     * @param string $passwordHash `password_hash()` value to store
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one, or the row is not this person's password
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person, the merges or the row cannot be loaded, or the secret cannot be written
     * @throws HilosException On database or truth-source failure while reading or writing the row
     */
    protected function writePasswordHash(int $identityId, string $passwordHash): void
    {
        $this->personToWrite();
        $password = Hilos::$db->identities[$identityId];
        if ($password === null || $password->userId !== $this->userId || $password->type !== IdentityType::PASSWORD) {
            throw new ValidationException(AuthMessages::NO_PASSWORD);
        }

        $password->setPasswordHash($passwordHash);
    }

    /**
     * Marks the person's password on an address a letter just proved as verified (HIL-1405).
     *
     * A row that is gone writes nothing and is not a refusal: the sign-in by letter goes on all the
     * same, and there is no password left to mark. Not final, for the reason
     * {@see self::renamePerson()} gives.
     *
     * @param int $identityId Password row on the proven address
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type, or the sync signal cannot be named
     * @throws DatabaseException When the person, the merges or the row cannot be loaded, or the flag cannot be written
     * @throws HilosException On database or truth-source failure while reading or writing the row
     */
    protected function markAddressVerified(int $identityId): void
    {
        $this->personToWrite();
        Hilos::$db->identities[$identityId]?->markVerified();
    }

    /**
     * Records a use of one of the person's passkeys - its counter and the time (HIL-1405).
     *
     * The library checked the signature and the counter before the hop; the counter rule is held
     * again here, against the counter stored now, because this agent is the one writer of the
     * person's keys and takes its frames one at a time - a second assertion of a cloned key that
     * passed the library's check alongside the first is refused here. A key that is gone or is not
     * this person's is refused in the words every failed passkey sign-in reads. Not final, for the
     * reason {@see self::renamePerson()} gives.
     *
     * @param int $passkeyId Row of the key
     * @param int $signCount Signature counter the authenticator reported
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one, or the key is not this person's
     * @throws WebAuthnVerificationException When the counter did not advance past the stored one
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person, the merges or the key cannot be loaded, or the use cannot be written
     * @throws HilosException On database or truth-source failure while reading or writing the key
     */
    protected function recordPasskeyUse(int $passkeyId, int $signCount): void
    {
        $this->personToWrite();
        foreach (Hilos::$db->passkeyCredentials->listByUser($this->userId) as $credential) {
            if ($credential->id === $passkeyId) {
                $credential->recordUse($signCount);

                return;
            }
        }

        throw new ValidationException(AuthMessages::INVALID_PASSKEY);
    }

    /**
     * Moves every password and sign-in-link row of the person from one address to another (HIL-1405).
     *
     * One transaction, so a move refused halfway leaves every row where it was. An address another
     * account took between the library's check and this write lands on the unique key, and is
     * refused in the words the profile has always shown. Not final, for the reason
     * {@see self::renamePerson()} gives.
     *
     * @param string $from Lowercased address the account holds now
     * @param string $to Lowercased address it moves to
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one, or another account holds the address
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person, the merges or the rows cannot be loaded
     * @throws HilosException When the rows or the transaction cannot be written
     */
    protected function moveEmail(string $from, string $to): void
    {
        $this->personToWrite();
        Database::transactionStart();
        try {
            Hilos::$db->identities->changeEmail($this->userId, $from, $to);
            Database::transactionCommit();
        } catch (DuplicateValueException | DuplicateEntryException) {
            $this->rollBack();

            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        } catch (HilosException $failure) {
            $this->rollBack();

            throw $failure;
        }
    }

    /**
     * Removes one of the person's sign-in methods, the passkey's credential first (HIL-1405).
     *
     * The users library refused a last method and someone else's before the hop; the primitive
     * asks both again ({@see Identities::deleteIdentity()}), and
     * a method already gone is removed without a word, as it always was. The order is the
     * contract: the credential goes FIRST and the anchor SECOND, because an interruption between
     * them has to leave the state that closes the account - an anchor without a credential is a
     * row the profile still lists and can be unlinked again, while a credential without an anchor
     * is a key that signs the person in on a passkey they were told they had removed. Not final,
     * for the reason {@see self::renamePerson()} gives.
     *
     * @param int $identityId Sign-in method to remove
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one, or the method is not this person's or their last
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person, the merges or the rows cannot be loaded or deleted
     * @throws HilosException On database or truth-source failure while reading or deleting the rows
     */
    protected function unlinkIdentity(int $identityId): void
    {
        $this->personToWrite();
        $identity = Hilos::$db->identities[$identityId];
        if ($identity !== null && $identity->userId === $this->userId && $identity->type === IdentityType::PASSKEY) {
            Hilos::$db->passkeyCredentials->deleteByIdentity($identityId);
        }

        Hilos::$db->identities->deleteIdentity($this->userId, $identityId);
    }

    /**
     * The writes of the person's second factor (HIL-1406).
     *
     * Not final: the framework's test stands replace it to make one of the writes fail.
     *
     * @return SecondFactorPersonEdits Built once per agent
     */
    protected function secondFactorEdits(): SecondFactorPersonEdits
    {
        return $this->secondFactorEdits ??= new SecondFactorPersonEdits($this->userId);
    }

    /**
     * Writes a rename and answers the users library with its row or its refusal.
     *
     * The refusals keep the sentences the administrator always read: a missing person and a name
     * the row refuses are said, and a storage or wiring failure travels as the placeholder with
     * its detail beside it, written to this agent's log.
     *
     * @param UserRenameSignalData $ask Whom to rename, to what, and on whose word
     * @throws InvalidArgumentException When the answer frame cannot be named or queued
     */
    private function handleRename(UserRenameSignalData $ask): void
    {
        $rename = null;
        $refusal = null;
        try {
            $rename = $this->renamePerson($ask->name, $ask->renamedByUserId);
        } catch (ValidationException $e) {
            $refusal = ActionRefusal::said(self::renameRefusalWords($this->userId, $e));
        } catch (WiringRefusal $wiring) {
            // Answered like the storage failure below rather than raised (HIL-575): the library
            // continues only on the answer, and a modal or a chat window is waiting on it. Its own
            // words - the name of a collection nobody here reads - are not an answer about this
            // rename, so they ride only as the detail an admin may quote.
            $this->logAgentError("Rename refused for userId={$this->userId}: {$wiring->getMessage()}");
            $refusal = ActionRefusal::fromThrowable($wiring);
        } catch (HilosException $e) {
            $this->logAgentError("Rename failed for userId={$this->userId}: {$e->getMessage()}");
            $refusal = ActionRefusal::fromThrowable($e);
        }

        $this->sendToAgent(
            $ask->replySignal,
            UserRenameDoneSignalData::to($ask, $rename === null ? null : (int)$rename->id, $refusal),
        );
    }

    /**
     * Runs one write and says why it was refused, if it was.
     *
     * Every failure is an answer, the wiring refusal included: the coordinator continues only on
     * the answer, and a card, a parked command or a browser action is waiting on it.
     *
     * @param callable(): void $write The write - a flag, or one of the person's ways of signing in
     * @param string $what Which write, for the log line
     * @return ?ActionRefusal Why the flag was not written, or null when it was
     */
    private function flagRefusal(callable $write, string $what): ?ActionRefusal
    {
        try {
            $write();
        } catch (Throwable $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("{$what} for #{$this->userId} failed: {$e->getMessage()}");
            }

            return $refusal;
        }

        return null;
    }

    /**
     * Runs one edit of the person's second factor and builds its answer, a refusal included (HIL-1406).
     *
     * An account that cannot be addressed - erased, or folded into someone else between the hop and
     * this frame - is refused before anything is read, in the words of
     * {@see AddressablePerson::require()}. A refusal a write raised for the person keeps its words;
     * anything else becomes the placeholder with its detail beside it, written to this agent's log.
     * The answer always goes: a browser action, the sweep or an operator's command is waiting on it.
     *
     * @template T of SignalDataInterface
     * @param Closure(): T $edit The edit, answering on its own when it went through or was refused on the merits
     * @param Closure(ActionRefusal): T $refused Builds the answer of a refused edit
     * @return T The answer to send back
     */
    private function secondFactorAnswer(Closure $edit, Closure $refused): SignalDataInterface
    {
        try {
            AddressablePerson::require($this->userId);

            return $edit();
        } catch (ValidationException $e) {
            return $refused(ActionRefusal::said($e->getMessage()));
        } catch (Throwable $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("Second factor of #{$this->userId} failed: {$e->getMessage()}");
            }

            return $refused($refusal);
        }
    }

    /**
     * @return User The person's row, open to an edit
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person or the merges cannot be loaded
     */
    private function personToWrite(): User
    {
        AddressablePerson::require($this->userId);
        $user = Hilos::$db->users[$this->userId];
        if (!$user instanceof User) {
            // The check above is the refusal. Asked again so a row gone on this read
            // keeps that same refusal instead of a second copy of its words.
            AddressablePerson::require($this->userId);

            throw new LogicException("Person #{$this->userId} is not a user row");
        }

        return $user;
    }

    /**
     * @param string $idString Row id as the sync frame spells it
     * @return bool True when the row is this person's
     */
    private function isThisPerson(string $idString): bool
    {
        // The frame spells the id as text. A strict compare with the int would never match.
        return $idString === (string)$this->userId;
    }

    /**
     * @param int $userId Person the frame names
     * @param string $name Frame name, for the refusal
     * @throws AgentException When the frame names another person than this agent's
     */
    private function refuseAnotherPerson(int $userId, string $name): void
    {
        if ($userId !== $this->userId) {
            throw new AgentException("{$name} for user #{$userId} reached the agent of user #{$this->userId}");
        }
    }

    /**
     * Rolls back a failed rename or address move without letting the cleanup replace the failure.
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
