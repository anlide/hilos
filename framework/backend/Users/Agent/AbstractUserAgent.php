<?php

declare(strict_types=1);

namespace Hilos\Users\Agent;

use Closure;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\SecondFactor\SecondFactorPersonEdits;
use Hilos\Auth\SecurityNotice\SecurityNotifier;
use Hilos\Auth\Session\BrowserTimeZone;
use Hilos\Auth\StepUp\StepUpConfirmations;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentIndexException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Item\UserActions;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\View\Collection\Identities;
use Hilos\Database\View\Item\UserRename;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationPreferencesChangedSignalData;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Notification\NotificationGroup;
use Hilos\Notification\NotificationSignalName;
use Hilos\Users\AddressablePerson;
use Hilos\Users\DTO\UserAccountDeletionCancelDoneSignalData;
use Hilos\Users\DTO\UserAccountDeletionCancelSignalData;
use Hilos\Users\DTO\UserAddressVerifyDoneSignalData;
use Hilos\Users\DTO\UserAddressVerifySignalData;
use Hilos\Users\DTO\UserAdminCommandDoneSignalData;
use Hilos\Users\DTO\UserAdminCommandSignalData;
use Hilos\Users\DTO\UserAdminWriteDoneSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteDoneSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeDoneSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeSignalData;
use Hilos\Users\DTO\UserEmailChangeDoneSignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkDoneSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserNotificationChannelSetSignalData;
use Hilos\Users\DTO\UserNotificationReadSignalData;
use Hilos\Users\DTO\UserPasskeyUseDoneSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasswordChangeDoneSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserPasswordRehashDoneSignalData;
use Hilos\Users\DTO\UserPasswordRehashSignalData;
use Hilos\Users\DTO\UserPasswordResetDoneSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Users\DTO\UserPhotoPutDoneSignalData;
use Hilos\Users\DTO\UserPhotoPutSignalData;
use Hilos\Users\DTO\UserPhotoRemoveDoneSignalData;
use Hilos\Users\DTO\UserPhotoRemoveSignalData;
use Hilos\Users\DTO\UserPushRemoveSignalData;
use Hilos\Users\DTO\UserPushUnsubscribeSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
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
use Hilos\Users\DTO\UserStepUpCreditDoneSignalData;
use Hilos\Users\DTO\UserStepUpCreditSignalData;
use Hilos\Users\DTO\UserStepUpRecordDoneSignalData;
use Hilos\Users\DTO\UserStepUpRecordSignalData;
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use Hilos\WiringRefusal;
use Throwable;

/**
 * Agent for one person. Its claims name the person's row and child sets; it keeps no copy of them.
 *
 * It writes the ordinary edits of the person's row - the name, the admin flag, the block
 * (HIL-1404) - and every edit of the person's ways of signing in and passkeys, sign-in included
 * (HIL-1405): a fresh password hash, a password verified by a letter, a passkey's counter and last
 * use, a new password from recovery or from the profile, an address moved, a method unlinked. It
 * writes the person's second factor too (HIL-1406): an app confirmed or disconnected, a code that
 * proves the person - a step taken, a backup code burned, a wrong code counted and the lock it puts
 * or an operator lifts - the removal wait, and a removal canceled, carried out or marked reminded
 * ({@see SecondFactorPersonEdits}). It records the person's confirmations of a protected operation
 * and takes away the trust of the person's browsers (HIL-1407): a confirmation proved by a password
 * or a code, a device key or a second-factor code, or credited to a refused sign-in of a blocked
 * person ({@see StepUpConfirmations}); the trust when the second factor goes, when the password is
 * changed or recovered, when the person is blocked and when the person's sessions are ended. It
 * writes the person's notification edits too (HIL-1408): one notification marked read or all of
 * them, a channel turned on or off, this browser's push subscription dropped, a push device
 * removed. It cancels a standing request to delete the account and sets or removes the profile
 * photo (HIL-1409), both on a frame from the users library; the library starts the deletion
 * request. Each comes as a frame from the coordinator that judged it: the name, the ways in, the
 * second factor and the confirmations from {@see AbstractUsersLibraryAgent}, the notification edits
 * from {@see AbstractNotificationsLibraryAgent}, the two flags, the credit and the ended sessions
 * from {@see AbstractSessionsLibraryAgent}. The theme choice comes from the coordinator that
 * accepts the person's pick (HIL-1427). The agent writes and always answers - a refusal included,
 * because the coordinator continues only on the answer and something is waiting on it - and the
 * coordinator does what follows the write. One frame is not an ask: the last usable zone a browser
 * of the person named, which the sessions library reports and nothing waits on (HIL-1582), is
 * written without an answer, and its refusal goes to this agent's log. Creating a way in, an
 * enrolment, a set of backup codes or a removal stays with the libraries that create it. When the
 * person is erased, or folded into someone else, the agent stops itself.
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
        HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE => [
            AgentSignalConfigKey::INDEX_FIELD => UserThemePickWriteSignalData::userId,
            AgentSignalConfigKey::DTO => UserThemePickWriteSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE => [
            AgentSignalConfigKey::INDEX_FIELD => UserTimeZoneWriteSignalData::userId,
            AgentSignalConfigKey::DTO => UserTimeZoneWriteSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE => [
            AgentSignalConfigKey::INDEX_FIELD => UserLastBrowserTimeZoneWriteSignalData::userId,
            AgentSignalConfigKey::DTO => UserLastBrowserTimeZoneWriteSignalData::class,
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
        HilosSignalConstants::HILOS_USER_STEP_UP_RECORD => [
            AgentSignalConfigKey::INDEX_FIELD => UserStepUpRecordSignalData::userId,
            AgentSignalConfigKey::DTO => UserStepUpRecordSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT => [
            AgentSignalConfigKey::INDEX_FIELD => UserStepUpCreditSignalData::userId,
            AgentSignalConfigKey::DTO => UserStepUpCreditSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE => [
            AgentSignalConfigKey::INDEX_FIELD => UserBrowserTrustRevokeSignalData::userId,
            AgentSignalConfigKey::DTO => UserBrowserTrustRevokeSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL => [
            AgentSignalConfigKey::INDEX_FIELD => UserAccountDeletionCancelSignalData::userId,
            AgentSignalConfigKey::DTO => UserAccountDeletionCancelSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PHOTO_PUT => [
            AgentSignalConfigKey::INDEX_FIELD => UserPhotoPutSignalData::userId,
            AgentSignalConfigKey::DTO => UserPhotoPutSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PHOTO_REMOVE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPhotoRemoveSignalData::userId,
            AgentSignalConfigKey::DTO => UserPhotoRemoveSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_NOTIFICATION_READ => [
            AgentSignalConfigKey::INDEX_FIELD => UserNotificationReadSignalData::userId,
            AgentSignalConfigKey::DTO => UserNotificationReadSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET => [
            AgentSignalConfigKey::INDEX_FIELD => UserNotificationChannelSetSignalData::userId,
            AgentSignalConfigKey::DTO => UserNotificationChannelSetSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPushUnsubscribeSignalData::userId,
            AgentSignalConfigKey::DTO => UserPushUnsubscribeSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PUSH_REMOVE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPushRemoveSignalData::userId,
            AgentSignalConfigKey::DTO => UserPushRemoveSignalData::class,
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
     *     one-to-one extension of the person's own row, not a new member of a set. The person's
     *     confirmations of a protected operation are claimed whole: a confirmation is the mark of the
     *     person's own proof in one browser, and the agent inserts or extends it and clears the
     *     person's expired ones in one write (owner's decision, 2026-10-09, HIL-1407). The row that
     *     says a channel is off is born only by the person's own choice, so that set is claimed whole
     *     (owner's decision, 2026-10-10, HIL-1408). The person's one photo row (`hilos_user_photo`,
     *     keyed by the person) is born the same way, by the first photo the person sets: the agent
     *     claims that set whole (owner's decision, 2026-10-10, HIL-1409). A claim that may add is not
     *     borrowed, so the start does not wait for it.
     */
    public const array OWNS_DB_SET = [
        HilosDbContext::identities => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::passkeyCredentials => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactors => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorBackupCodes => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorResets => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorSettings => [TruthSourceOperation::Add, TruthSourceOperation::Update],
        HilosDbContext::secondFactorTrusts => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::stepUps => TruthSourceOperation::ALL,
        HilosDbContext::accountDeletions => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::userPhotos => TruthSourceOperation::ALL,
        HilosDbContext::notifications => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::notificationPreferences => TruthSourceOperation::ALL,
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
     * A confirmation recorded and a trust taken away are answered as a flag is (HIL-1407): the
     * browser action waits on a confirmation, and the sessions library reads a refusal of a credit or
     * of a trust into its log. A notification marked read, a channel turned on or off, and a push
     * subscription dropped or a device removed are answered the same way (HIL-1408): the notifications
     * library waits on the answer, and the tabs hear a read or a channel change only after the write.
     * A standing deletion canceled, and a profile photo set or removed, are answered the same way
     * (HIL-1409): the users library waits on the answer. The last zone a browser named is the one
     * frame not answered (HIL-1582): the sessions library reports it in the background, so a refusal
     * is written to this agent's log.
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

            case HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE:
                $themePickWrite = $data->data;
                if (!$themePickWrite instanceof UserThemePickWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserThemePickWriteSignalData::class, $themePickWrite);
                }

                $this->refuseAnotherPerson($themePickWrite->userId, $name);
                $this->handleThemePickWrite($themePickWrite);

                return;

            case HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE:
                $timeZoneWrite = $data->data;
                if (!$timeZoneWrite instanceof UserTimeZoneWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserTimeZoneWriteSignalData::class, $timeZoneWrite);
                }

                $this->refuseAnotherPerson($timeZoneWrite->userId, $name);
                $this->handleTimeZoneWrite($timeZoneWrite);

                return;

            case HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE:
                $this->handleLastBrowserTimeZoneWrite($data->data, $name);

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
                    $this->flagRefusal(function () use ($passkeyUse): void {
                        $this->recordPasskeyUse($passkeyUse->passkeyId, $passkeyUse->signCount);
                        if ($passkeyUse->operation !== null && $passkeyUse->sessionTokenHash !== null) {
                            $this->recordStepUp($passkeyUse->sessionTokenHash, $passkeyUse->operation);
                        }
                    }, 'Passkey use'),
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
                    $this->flagRefusal(function () use ($reset): void {
                        $this->writeNewPassword($reset->identityId, $reset->passwordHash, $reset->keepSessionId);
                        $this->announceSecurity(fn () => SecurityNotifier::passwordReset($this->userId), 'Password recovery');
                    }, 'Password recovery'),
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
                    $this->flagRefusal(function () use ($change): void {
                        $this->writeNewPassword($change->identityId, $change->passwordHash, $change->keepSessionId);
                        $this->announceSecurity(fn () => SecurityNotifier::passwordChanged($this->userId), 'Password change');
                    }, 'Password change'),
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
                    fn () => $this->proveSecondFactor($prove),
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

            case HilosSignalConstants::HILOS_USER_STEP_UP_RECORD:
                $record = $data->data;
                if (!$record instanceof UserStepUpRecordSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserStepUpRecordSignalData::class, $record);
                }

                $this->refuseAnotherPerson($record->userId, $name);
                $this->sendToAgent($record->replySignal, UserStepUpRecordDoneSignalData::to(
                    $record,
                    $this->flagRefusal(fn () => $this->recordStepUp($record->sessionTokenHash, $record->operation), 'Step-up confirmation'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT:
                $credit = $data->data;
                if (!$credit instanceof UserStepUpCreditSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserStepUpCreditSignalData::class, $credit);
                }

                $this->refuseAnotherPerson($credit->userId, $name);
                $this->sendToAgent($credit->replySignal, UserStepUpCreditDoneSignalData::to(
                    $credit,
                    $this->flagRefusal(fn () => $this->recordStepUp($credit->sessionTokenHash, $credit->operation), 'Step-up credit'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE:
                $trustRevoke = $data->data;
                if (!$trustRevoke instanceof UserBrowserTrustRevokeSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserBrowserTrustRevokeSignalData::class, $trustRevoke);
                }

                $this->refuseAnotherPerson($trustRevoke->userId, $name);
                $this->sendToAgent($trustRevoke->replySignal, UserBrowserTrustRevokeDoneSignalData::to(
                    $trustRevoke,
                    $this->flagRefusal(fn () => $this->revokeBrowserTrust($trustRevoke->sessionId, $trustRevoke->others), 'Browser trust revoke'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL:
                $deletionCancel = $data->data;
                if (!$deletionCancel instanceof UserAccountDeletionCancelSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserAccountDeletionCancelSignalData::class, $deletionCancel);
                }

                $this->refuseAnotherPerson($deletionCancel->userId, $name);
                $this->sendToAgent($deletionCancel->replySignal, $this->personAnswer(
                    fn () => new UserAccountDeletionCancelDoneSignalData(
                        $deletionCancel,
                        $this->cancelAccountDeletion(),
                        null,
                        null,
                        null,
                    ),
                    fn (ActionRefusal $refusal) => UserAccountDeletionCancelDoneSignalData::refused($deletionCancel, $refusal),
                    'Account deletion cancel',
                ));

                return;

            case HilosSignalConstants::HILOS_USER_PHOTO_PUT:
                $photoPut = $data->data;
                if (!$photoPut instanceof UserPhotoPutSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPhotoPutSignalData::class, $photoPut);
                }

                $this->refuseAnotherPerson($photoPut->userId, $name);
                $this->sendToAgent($photoPut->replySignal, $this->personAnswer(
                    fn () => new UserPhotoPutDoneSignalData($photoPut, $this->putPhoto($photoPut->fileId), null, null, null),
                    fn (ActionRefusal $refusal) => UserPhotoPutDoneSignalData::refused($photoPut, $refusal),
                    'Profile photo',
                ));

                return;

            case HilosSignalConstants::HILOS_USER_PHOTO_REMOVE:
                $photoRemove = $data->data;
                if (!$photoRemove instanceof UserPhotoRemoveSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPhotoRemoveSignalData::class, $photoRemove);
                }

                $this->refuseAnotherPerson($photoRemove->userId, $name);
                $this->sendToAgent($photoRemove->replySignal, $this->personAnswer(
                    fn () => new UserPhotoRemoveDoneSignalData($photoRemove, $this->removePhoto(), null, null, null),
                    fn (ActionRefusal $refusal) => UserPhotoRemoveDoneSignalData::refused($photoRemove, $refusal),
                    'Profile photo removal',
                ));

                return;

            case HilosSignalConstants::HILOS_USER_NOTIFICATION_READ:
                $notificationRead = $data->data;
                if (!$notificationRead instanceof UserNotificationReadSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserNotificationReadSignalData::class, $notificationRead);
                }

                $this->refuseAnotherPerson($notificationRead->userId, $name);
                $this->sendToAgent($notificationRead->replySignal, HandoverAnswerSignalData::to(
                    $notificationRead,
                    $this->flagRefusal(fn () => $this->markNotificationsRead($notificationRead->notificationId), 'Notification read'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET:
                $channelSet = $data->data;
                if (!$channelSet instanceof UserNotificationChannelSetSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserNotificationChannelSetSignalData::class, $channelSet);
                }

                $this->refuseAnotherPerson($channelSet->userId, $name);
                $this->sendToAgent($channelSet->replySignal, HandoverAnswerSignalData::to(
                    $channelSet,
                    $this->flagRefusal(
                        fn () => $this->setNotificationChannel($channelSet->channel, $channelSet->enabled),
                        'Notification channel',
                    ),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE:
                $pushUnsubscribe = $data->data;
                if (!$pushUnsubscribe instanceof UserPushUnsubscribeSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPushUnsubscribeSignalData::class, $pushUnsubscribe);
                }

                $this->refuseAnotherPerson($pushUnsubscribe->userId, $name);
                $this->sendToAgent($pushUnsubscribe->replySignal, HandoverAnswerSignalData::to(
                    $pushUnsubscribe,
                    $this->flagRefusal(fn () => $this->unsubscribePush($pushUnsubscribe->endpoint), 'Push unsubscribe'),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_PUSH_REMOVE:
                $pushRemove = $data->data;
                if (!$pushRemove instanceof UserPushRemoveSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPushRemoveSignalData::class, $pushRemove);
                }

                $this->refuseAnotherPerson($pushRemove->userId, $name);
                $this->sendToAgent($pushRemove->replySignal, HandoverAnswerSignalData::to(
                    $pushRemove,
                    $this->flagRefusal(fn () => $this->removePush($pushRemove->subscriptionId), 'Push removal'),
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
     * null. Whether the person may be written at all - there, and not folded into another one,
     * whose name is a tombstone's (HIL-1292) - was settled at the entry of the request, before
     * this is called; the row is read here as required. What follows the commit - the notice to
     * the person, the project's hook - is the users library's, on the answer.
     *
     * Not final: the framework's test stands replace it to pin the route apart from the table.
     *
     * @param string $name Name to give; trimmed and held to the frame of {@see UserActions::rename()}
     * @param ?int $renamedByUserId Person who did the rename - the renamed person's own id when they renamed
     *     themselves - or null when the author is not a person
     * @return ?UserRename Journal row of this rename, or null when the name was already the person's
     * @throws ItemNotFoundForUpdateException When the person's row has no persisted id
     * @throws ValidationException When the name is empty, too short or too long
     * @throws LogicException When the people collection's class constants are not configured, or a transaction is already open
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person's row is not there, or cannot be loaded
     * @throws HilosException When the name, the journal row or the transaction cannot be written
     */
    protected function renamePerson(string $name, ?int $renamedByUserId): ?UserRename
    {
        $user = Hilos::$db->users[$this->userId];
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
            Database::transactionRollbackSilent();

            throw $failure;
        }

        return $rename;
    }

    /**
     * Writes the person's admin flag, and nothing else.
     *
     * Telling the person's browsers is the sessions library's, once this answers. An account
     * folded into another one is refused, both ways, at the entry of the request (HIL-1199): its
     * rights belong to the account it became. The row is read here as required. Not final, for the
     * reason {@see self::renamePerson()} gives.
     *
     * @param bool $admin New admin flag
     * @throws ItemNotFoundForUpdateException When the person's row has no persisted id
     * @throws LogicException When the people collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person's row is not there, or cannot be loaded
     * @throws HilosException On database failure while reading or writing the flag
     */
    protected function writeAdminFlag(bool $admin): void
    {
        Hilos::$db->users[$this->userId]->actions->setAdmin($admin);
    }

    /**
     * Writes the person's block flag; the sessions library ends the sessions once this answers.
     *
     * The same shape as {@see self::writeAdminFlag()}: a folded account is refused, both ways,
     * at the entry of the request - the merge closed its sign-in with the flag, and the flag is not
     * an administrator's to reopen - and the row is read here as required. A block takes away the
     * trust of every browser of the person in the
     * same transaction (HIL-1407): a blocked person who is let back in passes the second factor
     * again everywhere, and a block the trust survived did not happen whole. Lifting the block
     * writes the flag alone. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * A flag that really changed is announced to the person (HIL-294) - a block once the
     * transaction committed, a lift once the flag is written; blocking a blocked account says
     * nothing. A notice that cannot be queued does not undo the write ({@see self::announceSecurity()}).
     *
     * @param bool $block New block flag
     * @throws ItemNotFoundForUpdateException When the person's row has no persisted id
     * @throws LogicException When the people collection's class constants are not configured, or a transaction is already open
     * @throws InvalidArgumentException When a stored row is not the collection's object type, or a sync signal cannot be named
     * @throws DatabaseException When the person's row is not there or cannot be loaded, or the trust cannot be removed
     * @throws HilosException On database or truth-source failure while reading or writing the flag, the trust or the transaction
     */
    protected function writeBlockFlag(bool $block): void
    {
        $user = Hilos::$db->users[$this->userId];
        $wasBlocked = $user->block;
        if (!$block) {
            $user->actions->setBlock(false);
            if ($wasBlocked) {
                $this->announceSecurity(fn () => SecurityNotifier::accountUnblocked($this->userId), 'Account unblock');
            }

            return;
        }

        Database::transactionStart();
        try {
            Hilos::$db->secondFactorTrusts->actions->deleteForUser($this->userId);
            $user->actions->setBlock(true);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollbackSilent();

            throw $failure;
        }

        if (!$wasBlocked) {
            $this->announceSecurity(fn () => SecurityNotifier::accountBlocked($this->userId), 'Account block');
        }
    }

    /**
     * Writes this person's theme choice without touching the activity timestamp.
     *
     * Whether the account may be written was settled at the entry of the request; the row is
     * read here as required. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param ?string $themePick Light, dark or system; null for an explicit reset
     * @throws ItemNotFoundForUpdateException When the person's row has no persisted id
     * @throws ValidationException When the choice is outside the theme catalog
     * @throws LogicException When the people collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person's row is not there, or cannot be loaded
     * @throws HilosException On database or truth-source failure while writing the choice
     */
    protected function writeThemePick(?string $themePick): void
    {
        Hilos::$db->users[$this->userId]->actions->setThemePick($themePick);
    }

    /**
     * Writes this person's time zone without touching activity time.
     *
     * Whether the account may be written was settled at the entry of the request; the row is
     * read here as required. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param ?string $timeZone Exact IANA identifier or an explicit reset
     * @throws ItemNotFoundForUpdateException When the person's row has no persisted id
     * @throws ValidationException When the identifier is outside the IANA catalog
     * @throws LogicException When the people collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person's row is not there, or cannot be loaded
     * @throws HilosException On database or truth-source failure while writing the time zone
     */
    protected function writeTimeZone(?string $timeZone): void
    {
        Hilos::$db->users[$this->userId]->actions->setTimeZone($timeZone);
    }

    /**
     * Remembers the last usable zone a browser of this person named, without touching activity time (HIL-1582).
     *
     * The person's own zone choice stays as it is. Whether the account may be written was settled at
     * the entry of the frame; the row is read here as required. Not final, for the reason
     * {@see self::renamePerson()} gives.
     *
     * @param string $lastBrowserTimeZone Exact IANA identifier the browser named
     * @throws ItemNotFoundForUpdateException When the person's row has no persisted id
     * @throws ValidationException When the identifier is outside the IANA catalog
     * @throws LogicException When the people collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the person's row is not there, or cannot be loaded
     * @throws HilosException On database or truth-source failure while writing the zone
     */
    protected function writeLastBrowserTimeZone(string $lastBrowserTimeZone): void
    {
        Hilos::$db->users[$this->userId]->actions->setLastBrowserTimeZone($lastBrowserTimeZone);
    }

    /**
     * Stores a password hash on the person's password row (HIL-1405).
     *
     * One write for three asks - a rehash on sign-in, and a recovery and a change in the profile
     * through {@see self::writeNewPassword()} - because the write is the same and only what the users
     * library does after it differs. The hash was minted where the password arrived; the password
     * itself never reaches this agent. The row is the one the library checked, named by id: one that
     * is gone, is not a password, or is not this person's is refused rather than written somewhere
     * else. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param int $identityId Password row to write
     * @param string $passwordHash `password_hash()` value to store
     * @throws ValidationException When the row is not this person's password
     * @throws LogicException When the identities collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the row cannot be loaded, or the secret cannot be written
     * @throws HilosException On database or truth-source failure while reading or writing the row
     */
    protected function writePasswordHash(int $identityId, string $passwordHash): void
    {
        if (
            !isset(Hilos::$db->identities[$identityId])
            || Hilos::$db->identities[$identityId]->userId !== $this->userId
            || Hilos::$db->identities[$identityId]->type !== IdentityType::PASSWORD
        ) {
            throw new ValidationException(AuthMessages::NO_PASSWORD);
        }

        Hilos::$db->identities[$identityId]->setPasswordHash($passwordHash);
    }

    /**
     * Stores a new password of the person - recovered, or changed in the profile - after taking away
     * the trust of every other browser of the person (HIL-1407).
     *
     * A browser trusted to skip the second factor was trusted on the strength of the old password,
     * so the new one ends that trust everywhere but in the browser that set it. The two writes are
     * not one transaction - taking the trust away commits on its own, and the framework does not
     * nest transactions - so the order carries the guarantee: the trust goes FIRST and the hash
     * SECOND. A failure of either half refuses the password as not changed, and there is no moment
     * at which the password is new while the other browsers still pass without a code. Not final,
     * for the reason {@see self::renamePerson()} gives.
     *
     * @param int $identityId Password row to write
     * @param string $passwordHash `password_hash()` value to store
     * @param int $keepSessionId Session row of the browser that set the password, which keeps its trust; 0 when it has none
     * @throws ValidationException When the row is not this person's password
     * @throws LogicException When the identities collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the row cannot be loaded, or the secret cannot be written
     * @throws HilosException On database or truth-source failure while taking the trust away or writing the row
     */
    protected function writeNewPassword(int $identityId, string $passwordHash, int $keepSessionId): void
    {
        Hilos::$db->secondFactorTrusts->actions->deleteForUserExceptSession($this->userId, $keepSessionId);
        $this->writePasswordHash($identityId, $passwordHash);
    }

    /**
     * Marks the person's password on an address a letter just proved as verified (HIL-1405).
     *
     * A row that is gone writes nothing and is not a refusal: the sign-in by letter goes on all the
     * same, and there is no password left to mark. Not final, for the reason
     * {@see self::renamePerson()} gives.
     *
     * @param int $identityId Password row on the proven address
     * @throws LogicException When the identities collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type, or the sync signal cannot be named
     * @throws DatabaseException When the row cannot be loaded, or the flag cannot be written
     * @throws HilosException On database or truth-source failure while reading or writing the row
     */
    protected function markAddressVerified(int $identityId): void
    {
        if (!isset(Hilos::$db->identities[$identityId])) {
            return;
        }

        Hilos::$db->identities[$identityId]->markVerified();
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
     * @throws ValidationException When the key is not this person's
     * @throws WebAuthnVerificationException When the counter did not advance past the stored one
     * @throws InvalidArgumentException When the keys' query is given an invalid order direction
     * @throws DatabaseException When the keys cannot be loaded, or the use cannot be written
     * @throws HilosException On database or truth-source failure while reading or writing the key
     */
    protected function recordPasskeyUse(int $passkeyId, int $signCount): void
    {
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
     * @throws ValidationException When another account holds the address
     * @throws LogicException When a transaction is already open
     * @throws InvalidArgumentException When the rows' query is given an invalid order direction
     * @throws DatabaseException When the rows cannot be loaded or rewritten
     * @throws HilosException When the rows or the transaction cannot be written
     */
    protected function moveEmail(string $from, string $to): void
    {
        Database::transactionStart();
        try {
            Hilos::$db->identities->changeEmail($this->userId, $from, $to);
            Database::transactionCommit();
        } catch (DuplicateValueException | DuplicateEntryException) {
            Database::transactionRollbackSilent();

            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        } catch (HilosException $failure) {
            Database::transactionRollbackSilent();

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
     * @throws ValidationException When the method is not this person's or their last
     * @throws LogicException When the identities collection's class constants are not configured, or a passkey method is
     *     deleted while its credential is still stored
     * @throws InvalidArgumentException When a stored row is not the collection's object type, or a query is given an invalid order
     * @throws DatabaseException When the rows cannot be loaded or deleted
     * @throws HilosException On database or truth-source failure while reading or deleting the rows
     */
    protected function unlinkIdentity(int $identityId): void
    {
        if (
            isset(Hilos::$db->identities[$identityId])
            && Hilos::$db->identities[$identityId]->userId === $this->userId
            && Hilos::$db->identities[$identityId]->type === IdentityType::PASSKEY
        ) {
            Hilos::$db->passkeyCredentials->deleteByIdentity($identityId);
        }

        Hilos::$db->identities->deleteIdentity($this->userId, $identityId);
    }

    /**
     * Records the person's confirmation of one operation in one browser, and tells every tab of it (HIL-1407).
     *
     * A confirmation is the mark of the person's own proof, so this agent is its one writer. One
     * write for four paths - a password or a code checked by the users library, a device key and a
     * second-factor code recorded in the turn that writes the proof, and a refused sign-in of a
     * blocked person credited by the sessions library - because the mark is the same and only who
     * judged the proof differs. The person's expired confirmations are cleared in the same write
     * ({@see StepUpConfirmations::record()}). Not final, for the reason {@see self::renamePerson()}
     * gives.
     *
     * @param string $sessionTokenHash Hash of the session token of the browser the operation is confirmed in
     * @param string $operation Protected operation confirmed
     * @throws InvalidArgumentException When the confirmations' query is invalid, or the frame cannot be named or queued
     * @throws DatabaseException When a confirmation cannot be read, written or removed
     * @throws HilosException On truth-source failure while writing a confirmation, or when the lifetime cannot be read
     */
    protected function recordStepUp(string $sessionTokenHash, string $operation): void
    {
        StepUpConfirmations::record($this, $sessionTokenHash, $this->userId, $operation);
    }

    /**
     * Takes away the trust of the person's browsers whose sessions were ended (HIL-1407).
     *
     * The one browser named for "end this session"; for "end the other sessions" every browser of
     * the person but the one named, and every one when the named one is 0. The sessions library ended
     * the sessions and answered the browser before it asked. Not final, for the reason
     * {@see self::renamePerson()} gives.
     *
     * @param int $sessionId Session row of the browser losing trust, or with others the one keeping it
     * @param bool $others Whether every browser of the person but the named one loses trust
     * @throws HilosException On truth-source, lookup, transaction or delete failure while taking the trust away
     */
    protected function revokeBrowserTrust(int $sessionId, bool $others): void
    {
        if ($others) {
            Hilos::$db->secondFactorTrusts->actions->deleteForUserExceptSession($this->userId, $sessionId);

            return;
        }

        Hilos::$db->secondFactorTrusts->actions->deleteForPair($sessionId, $this->userId);
    }

    /**
     * Marks one of the person's notifications read, or every one, and tells every tab (HIL-1408).
     *
     * A missing row, or a row of another person, is the same refusal. An already-read row is left
     * as it is, and the tabs still hear which one was marked. Not final, for the reason
     * {@see self::renamePerson()} gives.
     *
     * @param ?int $notificationId Notification to mark, or null for every one of the person's
     * @throws ItemNotFoundForUpdateException When the notification row has no id
     * @throws ValidationException When the notification is missing or another person's
     * @throws LogicException When the notifications collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type, or the frame cannot be named or queued
     * @throws DatabaseException When a notification cannot be loaded
     * @throws HilosException When a notification cannot be marked read
     */
    protected function markNotificationsRead(?int $notificationId): void
    {
        if ($notificationId === null) {
            Hilos::$db->notifications->actions->markAllReadForUser($this->userId);
        } else {
            if (
                !isset(Hilos::$db->notifications[$notificationId])
                || Hilos::$db->notifications[$notificationId]->userId !== $this->userId
            ) {
                throw new ValidationException('Notification not found');
            }

            Hilos::$db->notifications[$notificationId]->actions->markRead();
        }

        $this->sendToGroup(
            NotificationSignalName::READ,
            NotificationGroup::forUser($this->userId),
            new NotificationReadSignalData($notificationId ?? NotificationReadSignalData::ALL),
        );
    }

    /**
     * Turns one of the person's notification channels on or off, and tells every tab the full map (HIL-1408).
     *
     * Turning a channel off is what brings the muted row into being; turning it on removes that row.
     * Repeating either leaves the table as it is, and the tabs still hear the whole map. Not final,
     * for the reason {@see self::renamePerson()} gives.
     *
     * @param string $channel Channel to set
     * @param bool $enabled Whether the channel stays on
     * @throws EmptyValueException When the channel name is empty
     * @throws InvalidArgumentException When the preferences' query is invalid, or the frame cannot be named or queued
     * @throws DatabaseException When a preference or a channel address cannot be loaded or written
     * @throws SourceChangeSubscriberException Whatever a subscriber to the preference store announcement raises
     * @throws SettingException When the settings accessor refuses a channel's enablement key
     * @throws DbCollectionNotReadableException When nothing here reads the preferences or a channel's address collection
     * @throws HilosException When the truth source refuses the preference write
     */
    protected function setNotificationChannel(string $channel, bool $enabled): void
    {
        Hilos::$db->notificationPreferences->actions->setChannel($this->userId, $channel, $enabled);
        $this->sendToGroup(
            NotificationSignalName::PREFERENCES_CHANGED,
            NotificationGroup::forUser($this->userId),
            new NotificationPreferencesChangedSignalData(
                new NotificationChannelPreferenceProjector()->channelPreferenceMap($this->userId),
            ),
        );
    }

    /**
     * Drops the person's push subscription at one address (HIL-1408).
     *
     * A missing row, or a row of another person, is left as it is and still counts as done. The
     * tabs are not told: a subscription is one browser's. Not final, for the reason
     * {@see self::renamePerson()} gives.
     *
     * @param string $endpoint Browser push endpoint of the subscription to drop
     * @throws InvalidArgumentException When the lookup order is invalid
     * @throws DatabaseException When the subscription cannot be loaded or removed
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws WriteNotAllowedException When the caller holds no right to remove that person's subscriptions
     */
    protected function unsubscribePush(string $endpoint): void
    {
        Hilos::$db->pushSubscriptions->actions->unsubscribeOwned($this->userId, $endpoint);
    }

    /**
     * Removes one of the person's push devices (HIL-1408).
     *
     * A row that is already gone, or that belongs to someone else, is refused. The tabs are not
     * told. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param int $subscriptionId Push subscription row to remove
     * @throws ValidationException When the device is already gone
     * @throws LogicException When the collection has no usable object metadata
     * @throws DatabaseException When the subscription cannot be loaded or removed
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws WriteNotAllowedException When the caller holds no right to remove that person's subscriptions
     */
    protected function removePush(int $subscriptionId): void
    {
        if (Hilos::$db->pushSubscriptions->actions->removeOwned($this->userId, $subscriptionId) === false) {
            throw new ValidationException('This device is already gone');
        }
    }

    /**
     * Cancels the person's standing request to delete the account, if one still stands (HIL-1409).
     *
     * The users library started the request and judged the cancel. This write only ends a request
     * that is still live: a missing one, or one the erasure already carried out, answers false, and
     * the race is the database's conditional update. Not final, for the reason
     * {@see self::renamePerson()} gives.
     *
     * @return bool True when this call canceled a standing request, false when none stood
     * @throws LogicException When the requests collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type, or a sync signal cannot be named
     * @throws DatabaseException When the request cannot be loaded or updated
     * @throws HilosException On database or truth-source failure while reading or canceling the request
     */
    protected function cancelAccountDeletion(): bool
    {
        $deletion = Hilos::$db->accountDeletions->liveOf($this->userId);

        return $deletion !== null && $deletion->actions->cancel();
    }

    /**
     * Sets the person's profile photo from a published file, returning the file it replaced (HIL-1409).
     *
     * The row is born by the first photo and replaced by the next, one transaction either way, so a
     * failed write leaves the former photo. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param int $fileId Registry file the photo is set from
     * @return ?int Registry file of the photo this one replaced, or null when there was none
     * @throws LogicException When a transaction is already open
     * @throws HilosException When the photo or the transaction cannot be written
     */
    protected function putPhoto(int $fileId): ?int
    {
        Database::transactionStart();
        try {
            $former = Hilos::$db->userPhotos->actions->put($this->userId, $fileId);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollbackSilent();

            throw $failure;
        }

        return $former;
    }

    /**
     * Removes the person's profile photo, returning the file it held (HIL-1409).
     *
     * No photo is not a refusal: there is nothing to remove, and no transaction is opened. Not final,
     * for the reason {@see self::renamePerson()} gives.
     *
     * @return ?int Registry file of the photo that was removed, or null when there was none
     * @throws LogicException When the photos collection's class constants are not configured, or a transaction is already open
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the photo cannot be loaded or removed
     * @throws HilosException When the photo or the transaction cannot be written
     */
    protected function removePhoto(): ?int
    {
        if (!isset(Hilos::$db->userPhotos[$this->userId])) {
            return null;
        }

        Database::transactionStart();
        try {
            $fileId = Hilos::$db->userPhotos->actions->deleteForUser($this->userId);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollbackSilent();

            throw $failure;
        }

        return $fileId;
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
     * The person is checked first, on every frame ({@see AddressablePerson::require()}): one
     * erased, or folded into someone else between the hop and this frame, is refused rather than
     * written. The refusals keep the sentences the administrator always read: a missing person and
     * a name the row refuses are said, a folded account reads as a name the row refuses, and a
     * storage or wiring failure travels as the placeholder with its detail beside it, written to
     * this agent's log.
     *
     * @param UserRenameSignalData $ask Whom to rename, to what, and on whose word
     * @throws InvalidArgumentException When the answer frame cannot be named or queued
     */
    private function handleRename(UserRenameSignalData $ask): void
    {
        $rename = null;
        $refusal = null;
        try {
            AddressablePerson::require($this->userId);
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
     * Restates the person's sessions after a write or a conditional skip. Announcement failure
     * leaves the successful outcome intact; a later handshake carries the stored choice.
     *
     * @param UserThemePickWriteSignalData $ask Theme choice and the coordinator's waiting action
     * @throws InvalidArgumentException When the answer frame cannot be named or queued
     */
    private function handleThemePickWrite(UserThemePickWriteSignalData $ask): void
    {
        $refusal = $this->flagRefusal(function () use ($ask): void {
            if (
                !$ask->adoptOnly
                || (Hilos::$db->users[$this->userId]->themePick === null && !Hilos::$db->users[$this->userId]->themePickInitialized)
            ) {
                $this->writeThemePick($ask->themePick);
            }
        }, 'Theme pick');

        if ($refusal === null) {
            try {
                $this->sendToAgent(
                    HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE,
                    new UserSessionsRestateSignalData($this->userId),
                );
            } catch (Throwable $failure) {
                $this->logAgentError("Theme pick restate for #{$this->userId} failed: {$failure->getMessage()}");
            }
        }

        $this->sendToAgent($ask->replySignal, UserThemePickWriteDoneSignalData::to($ask, $refusal));
    }

    /**
     * Announces a successful write, including a repeat; announcement failure keeps success intact.
     *
     * @param UserTimeZoneWriteSignalData $ask Time zone and the coordinator's waiting action
     * @throws InvalidArgumentException When the answer frame cannot be named or queued
     */
    private function handleTimeZoneWrite(UserTimeZoneWriteSignalData $ask): void
    {
        $refusal = $this->flagRefusal(fn () => $this->writeTimeZone($ask->timeZone), 'Time zone');

        if ($refusal === null) {
            try {
                $this->sendToAgent(
                    HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE,
                    new UserSessionsRestateSignalData($this->userId),
                );
            } catch (Throwable $failure) {
                $this->logAgentError("Time zone restate for #{$this->userId} failed: {$failure->getMessage()}");
            }
        }

        $this->sendToAgent($ask->replySignal, UserTimeZoneWriteDoneSignalData::to($ask, $refusal));
    }

    /**
     * Remembers the zone a browser of this person named; a background write that answers nobody (HIL-1582).
     *
     * The person is checked first ({@see AddressablePerson::require()}): one erased, or folded into
     * someone else, is not written, and the write is not carried over to another person. A zone the
     * person's memory already holds writes nothing and opens no receipt - every reconnect of a tab
     * repeats it. A change rides the receipt the frame came under, a sign-in's, and otherwise gets
     * this agent's own. Any refusal goes to this agent's log, without the zone: it is the person's data.
     *
     * @param UserLastBrowserTimeZoneWriteSignalData $report Person at the keyboard and the zone the browser named
     * @param string $name Frame name, for the refusal
     * @throws AgentException When the frame names another person
     */
    private function handleLastBrowserTimeZoneWrite(UserLastBrowserTimeZoneWriteSignalData $report, string $name): void
    {
        $this->refuseAnotherPerson($report->userId, $name);
        try {
            AddressablePerson::require($this->userId);
            if (Hilos::$db->users[$this->userId]->lastBrowserTimeZone === $report->lastBrowserTimeZone) {
                return;
            }

            if (JournalReceiptScope::currentId() === null) {
                JournalReceiptScope::run(
                    JournalReceiptData::agent(BrowserTimeZone::JOURNAL_ACTION, $this->getId()),
                    fn () => $this->writeLastBrowserTimeZone($report->lastBrowserTimeZone),
                );
            } else {
                $this->writeLastBrowserTimeZone($report->lastBrowserTimeZone);
            }
        } catch (WiringRefusal $wiring) {
            // Told apart from a refused write: no later report cures a process that may not read the person.
            $this->logAgentError("Last browser time zone for #{$this->userId} refused by wiring: {$wiring->getMessage()}");
        } catch (Throwable $e) {
            $this->logAgentError("Last browser time zone for #{$this->userId} not written: {$e->getMessage()}");
        }
    }

    /**
     * Runs one write and says why it was refused, if it was.
     *
     * The person is checked first, on every frame ({@see AddressablePerson::require()}): one
     * erased, or folded into someone else between the hop and this frame, is refused in that
     * check's own words, and nothing is written. Every failure is an answer, the wiring refusal
     * included: the coordinator continues only on the answer, and a card, a parked command or a
     * browser action is waiting on it.
     *
     * @param callable(): void $write The write - a flag, or one of the person's ways of signing in
     * @param string $what Which write, for the log line
     * @return ?ActionRefusal Why the write was refused, or null when it was written
     */
    private function flagRefusal(callable $write, string $what): ?ActionRefusal
    {
        try {
            AddressablePerson::require($this->userId);
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
     * Hands one security announcement of the person's to the notifier (HIL-294).
     *
     * It follows a write that already went through - the password is new, the block is in place -
     * so a notice that cannot be queued is written to this agent's log and is not a refusal: the
     * write keeps its answer, the way the letter about a moved address does.
     *
     * @param Closure(): void $notice The announcement, one of {@see SecurityNotifier}'s
     * @param string $what Which write the notice follows, for the log line
     */
    private function announceSecurity(Closure $notice, string $what): void
    {
        try {
            $notice();
        } catch (HilosException $e) {
            $this->logAgentError("{$what} notice for #{$this->userId} failed: {$e->getMessage()}");
        }
    }

    /**
     * Runs one edit of the person's own and builds its answer, a refusal included.
     *
     * An account that cannot be addressed - erased, or folded into someone else between the hop and
     * this frame - is refused before anything is read, in the words of
     * {@see AddressablePerson::require()}. A refusal a write raised for the person keeps its words;
     * anything else becomes the placeholder with its detail beside it, written to this agent's log.
     * The answer always goes: a browser action, the sweep or an operator's command is waiting on it.
     * The second factor was the first edit answered this way (HIL-1406); a deletion canceled and a
     * profile photo set or removed are answered the same way (HIL-1409).
     *
     * @template T of SignalDataInterface
     * @param Closure(): T $edit The edit, answering on its own when it went through or was refused on the merits
     * @param Closure(ActionRefusal): T $refused Builds the answer of a refused edit
     * @param string $what Which edit, for the log line
     * @return T The answer to send back
     */
    private function personAnswer(Closure $edit, Closure $refused, string $what): SignalDataInterface
    {
        try {
            AddressablePerson::require($this->userId);

            return $edit();
        } catch (ValidationException $e) {
            return $refused(ActionRefusal::said($e->getMessage()));
        } catch (Throwable $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("{$what} of #{$this->userId} failed: {$e->getMessage()}");
            }

            return $refused($refusal);
        }
    }

    /**
     * Runs one edit of the person's second factor and builds its answer (HIL-1406).
     *
     * @template T of SignalDataInterface
     * @param Closure(): T $edit The edit, answering on its own when it went through or was refused on the merits
     * @param Closure(ActionRefusal): T $refused Builds the answer of a refused edit
     * @return T The answer to send back
     */
    private function secondFactorAnswer(Closure $edit, Closure $refused): SignalDataInterface
    {
        return $this->personAnswer($edit, $refused, 'Second factor');
    }

    /**
     * Checks a second-factor code and, when it confirms an operation and proves the person, records
     * the confirmation in the same turn (HIL-1407).
     *
     * The proof opens the operation only once both are written. A missed or refused code records
     * nothing. A confirmation that fails after the code was accepted refuses the proof, and the code
     * stays spent - as a device key's counter stays moved when its confirmation fails.
     *
     * @param UserSecondFactorProveSignalData $ask The code, where it was typed, and the operation it confirms if any
     * @return UserSecondFactorProveDoneSignalData Whether it proved the person, what it counted, and what it canceled
     * @throws InvalidArgumentException When the confirmations' query is invalid, or the frame cannot be named or queued
     * @throws DatabaseException When a confirmation cannot be read, written or removed
     * @throws HilosException When a lookup, a write or the env read of the check or of the confirmation fails
     */
    private function proveSecondFactor(UserSecondFactorProveSignalData $ask): UserSecondFactorProveDoneSignalData
    {
        $proof = $this->secondFactorEdits()->prove($ask);
        if ($proof->error === null && $ask->operation !== null && $ask->sessionTokenHash !== null) {
            $this->recordStepUp($ask->sessionTokenHash, $ask->operation);
        }

        return $proof;
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
}
