<?php

declare(strict_types=1);

namespace Hilos\Users\Agent;

use Closure;
use Hilos\Auth\AccountOperation\AccountErasureDeliveryCapture;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasurePersonalCapture;
use Hilos\Auth\AccountOperation\AccountErasurePersonalCaptureResult;
use Hilos\Auth\AccountOperation\AccountErasurePersonalFinal;
use Hilos\Auth\AccountOperation\AccountErasureProjectResult;
use Hilos\Auth\AccountOperation\AccountErasureStart;
use Hilos\Auth\AccountOperation\AccountOperationActivation;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalRefusedException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureUncapturedException;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
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
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
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
use Hilos\Database\Entity\Item\UserPhoto as EntityUserPhoto;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\View\Collection\Identities;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Database\View\Item\UserRename;
use Hilos\Files\HilosFiles;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalDocument;
use Hilos\Notification\DTO\NotificationPreferencesChangedSignalData;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Notification\NotificationGroup;
use Hilos\Notification\NotificationSignalName;
use Hilos\Users\AccountErasure;
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
use Hilos\Users\DTO\UserBrowserTrustRenewDoneSignalData;
use Hilos\Users\DTO\UserBrowserTrustRenewSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeDoneSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeSignalData;
use Hilos\Users\DTO\UserEmailChangeDoneSignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkDoneSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserIdentityProviderNameWriteSignalData;
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
use Hilos\Users\DTO\UserPushEndpointGoneSignalData;
use Hilos\Users\DTO\UserPushReleaseDoneSignalData;
use Hilos\Users\DTO\UserPushReleaseSignalData;
use Hilos\Users\DTO\UserPushRemoveSignalData;
use Hilos\Users\DTO\UserPushSubscribeSignalData;
use Hilos\Users\DTO\UserPushUnsubscribeSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserSecondFactorCodesDropDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorCodesDropSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollDropDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollDropSignalData;
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
use Hilos\Users\DTO\UserSecondFactorTrustRestrictDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockSignalData;
use Hilos\Users\DTO\UserLegalHoldForgetDoneSignalData;
use Hilos\Users\DTO\UserLegalHoldForgetSignalData;
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
 * extends a standing trust on the sessions library's frame when the second factor is confirmed
 * again (HIL-1907). It applies the stored trust restrictions to the person's trusts on a service
 * frame from any sender (HIL-1900) and by itself at every start (HIL-1901), a folded account
 * included: that one answers and stops again. It
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
 * coordinator does what follows the write. Background reports of the last usable browser zone
 * and an OAuth identity's provider name, and the transport's dead-endpoint reports, are written
 * without an answer; their refusals go to this agent's log. Creating a way in, an enrolment,
 * a set of backup codes or a removal stays with the libraries that create it.
 *
 * The agent follows the state of its person (HIL-1939). Open: every frame is served. Erasure
 * begun - the person's deletion request is marked started, the first step of the agent's part in
 * the erasure ({@see AccountErasureStart}): the ordinary frames are refused in the words of
 * {@see AddressablePerson::require()}, the service frames and the steps of the person's own
 * account operation are served, and the agent lives until its last write. Folded into someone
 * else: the service frames are served, and after each of them the agent stops. The person's own
 * row deleted: the agent stops. The service frames skip that check - the legal hold forgotten
 * ({@see HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET}), the trust restrictions applied
 * ({@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT}), a dead push endpoint
 * marked gone ({@see HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE}) and a push endpoint
 * released to another person ({@see HilosSignalConstants::HILOS_USER_PUSH_RELEASE}). Once the
 * account has closed, the agent captures the person's addresses and whatever the project asks for
 * before the ways in are deleted ({@see captureForErasure()}, HIL-1940). After the capture the
 * agent deletes its own child sets as its part of the erasure ({@see eraseAccountPersonalPart()},
 * HIL-1941). Once every required part of the account has its result, the agent's final deletes its
 * merge row, its rename journal, its capture and its own row, completes the deletion request, and
 * the agent stops after this last write ({@see finishAccountErasure()}, HIL-1942); the right to
 * delete the person's row, journal and merge row it receives at the switch (HIL-1952).
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
        HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE => [
            AgentSignalConfigKey::INDEX_FIELD => UserIdentityProviderNameWriteSignalData::userId,
            AgentSignalConfigKey::DTO => UserIdentityProviderNameWriteSignalData::class,
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
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_DROP => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorEnrollDropSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorEnrollDropSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_DROP => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorCodesDropSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorCodesDropSignalData::class,
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
        HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET => [
            AgentSignalConfigKey::INDEX_FIELD => UserLegalHoldForgetSignalData::userId,
            AgentSignalConfigKey::DTO => UserLegalHoldForgetSignalData::class,
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
        HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW => [
            AgentSignalConfigKey::INDEX_FIELD => UserBrowserTrustRenewSignalData::userId,
            AgentSignalConfigKey::DTO => UserBrowserTrustRenewSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT => [
            AgentSignalConfigKey::INDEX_FIELD => UserSecondFactorTrustRestrictSignalData::userId,
            AgentSignalConfigKey::DTO => UserSecondFactorTrustRestrictSignalData::class,
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
        HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPushSubscribeSignalData::userId,
            AgentSignalConfigKey::DTO => UserPushSubscribeSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPushUnsubscribeSignalData::userId,
            AgentSignalConfigKey::DTO => UserPushUnsubscribeSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PUSH_REMOVE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPushRemoveSignalData::userId,
            AgentSignalConfigKey::DTO => UserPushRemoveSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPushEndpointGoneSignalData::userId,
            AgentSignalConfigKey::DTO => UserPushEndpointGoneSignalData::class,
        ],
        HilosSignalConstants::HILOS_USER_PUSH_RELEASE => [
            AgentSignalConfigKey::INDEX_FIELD => UserPushReleaseSignalData::userId,
            AgentSignalConfigKey::DTO => UserPushReleaseSignalData::class,
        ],
    ];

    /** @var array<string, list<TruthSourceOperation>> The person's row, excluding creation and removal. */
    public const array OWNS_DB_ROWS = [
        HilosDbContext::users => [TruthSourceOperation::Update],
    ];

    /**
     * @var array<string, list<TruthSourceOperation>> The person's borrowed child sets, and the rename
     *     journal: added with the name, its feed link written here.
     *     The ways of signing in and the passkeys are written here since HIL-1405, and the second factor
     *     since HIL-1406; their creation is the libraries', and the unfinished enrolment a new start
     *     replaces is dropped here, on the library's frame (HIL-1903), and so is the set of backup codes a new
     *     issue replaces (HIL-1904). One row is born here too: the person's
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
     *     borrowed, so the start does not wait for it. Legal acceptances are removed here only by the test hold command.
     *     The results of the agent's own steps in an account operation are added here and never
     *     changed (HIL-1939): their set is keyed by the agent's full id, not by the person's. The
     *     capture of the ways in is removed here too, in the final's transaction (HIL-1942).
     *     The second-factor settings are also removed here, in the agent's part of the erasure (HIL-1941).
     */
    public const array OWNS_DB_SET = [
        HilosDbContext::identities => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::passkeyCredentials => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactors => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorBackupCodes => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorResets => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorSettings => [TruthSourceOperation::Add, TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorTrusts => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::stepUps => TruthSourceOperation::ALL,
        HilosDbContext::accountDeletions => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::legalAcceptances => [TruthSourceOperation::Remove],
        HilosDbContext::userPhotos => TruthSourceOperation::ALL,
        HilosDbContext::notifications => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::notificationPreferences => TruthSourceOperation::ALL,
        HilosDbContext::pushSubscriptions => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::userRenames => [TruthSourceOperation::Add, TruthSourceOperation::Update],
        HilosDbContext::accountOperationResults => [TruthSourceOperation::Add, TruthSourceOperation::Remove],
    ];

    /**
     * @var list<string> The stored trust restrictions, read when the person's trusts are restricted
     *     (HIL-1900): the settings library writes them, and this agent applies them to the person's
     *     trusts it edits and removes. The account operations and their assignments, read when the
     *     agent runs its step of one (HIL-1939): the session holder writes them. Their results are
     *     not listed here, because the set claim above already reads them. The deliveries, read when
     *     the erasure part checks every notification's deliveries are captured (HIL-1941).
     */
    public const array READS_DB = [
        HilosDbContext::secondFactorTrustRestrictions,
        HilosDbContext::accountOperationAssignments,
        HilosDbContext::accountOperations,
        HilosDbContext::notificationDeliveries,
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
     * The results of the agent's account operation steps are keyed by the agent's full id, the
     * owner their assignment names (HIL-1939); every other set by the person's id.
     *
     * @param string $collection Collection whose set claim is being resolved
     * @return string The agent's full id for the results, the person's root set key otherwise
     */
    public function ownedDbSetKey(string $collection): string
    {
        if ($collection === HilosDbContext::accountOperationResults) {
            return $this->getId();
        }

        return (string)$this->userId;
    }

    /**
     * Captures the person's addresses and the project's part as the agent's step in the erasure of
     * the account, before the ways in are deleted (HIL-1940); a repeat answers the saved capture.
     *
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $assignmentId Capture assignment the coordinator gave this agent
     * @return AccountOperationResult Committed capture or the saved one of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws ValidationException When the operation is no erasure, the assignment is not this person's
     *     capture, or the account has not closed yet
     * @throws Throwable When storage or the project's part fails, preserving the original failure
     */
    public function captureForErasure(int $assignmentId): AccountOperationResult
    {
        return AccountErasurePersonalCapture::run(
            $assignmentId,
            $this->userId,
            fn (): array => $this->erasureCaptureProjectPart($this->userId),
        );
    }

    /**
     * Deletes the person's child sets as the agent's part in the erasure of the account (HIL-1941);
     * a repeat answers the saved result without doing the work again.
     *
     * Called by the agent in its own frame with the person's expected part the census wrote. Before
     * any work the assignment must be an erasure's, the person's own expected part, and name this
     * agent's account; the person's addresses must be captured, and so must every delivery of the
     * person's notifications. Each refusal writes nothing. Inside the step's transaction the agent
     * deletes its child sets ({@see erasePersonalChildren()}), and what it erased is recorded as the
     * result in the same transaction, so a failure rolls back both. The photo's registry file leaves
     * for the files library only once that transaction commits ({@see HilosFiles::remove()}); a failed
     * removal is logged and not repeated.
     *
     * SCAFFOLD: no live caller until the erasure coordinator delivers the assignment (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $assignmentId The person's expected part the coordinator gave this agent
     * @return AccountOperationResult Committed result or the saved outcome of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws ValidationException When the operation is no erasure, or the assignment is not this person's erasure part
     * @throws AccountErasureUncapturedException When the person's addresses or some notifications' deliveries are not captured yet
     * @throws Throwable When storage or the erasure fails, preserving the original failure
     */
    public function eraseAccountPersonalPart(int $assignmentId): AccountOperationResult
    {
        return AccountOperationParticipantStep::run(
            $assignmentId,
            function (AccountOperationAssignment $assignment): AccountOperationResultPayload {
                if ($assignment->operation?->kind !== AccountOperationKind::Erasure) {
                    throw new ValidationException('An account erasure part must belong to an erasure.');
                }
                if (!AccountOperationExpectation::isExpectKey($assignment->assignmentKey)
                    || AccountOperationExpectation::participantOf($assignment->payload) !== AccountErasureParticipantCatalog::PERSONAL) {
                    throw new ValidationException('This assignment is not the person\'s erasure part.');
                }
                if ($assignment->userId !== $this->userId) {
                    throw new ValidationException('An account erasure part must name its own account.');
                }
                if (AccountErasurePersonalCaptureResult::of($assignment->operationId, $this->userId) === null) {
                    throw AccountErasureUncapturedException::addresses();
                }
                $notificationIds = AccountErasureDeliveryCapture::uncapturedNotificationIdsOf($assignment->operationId, $this->userId);
                if ($notificationIds !== []) {
                    throw AccountErasureUncapturedException::deliveries($notificationIds);
                }
                $erasure = $this->erasePersonalChildren($this->userId);
                if ($erasure->fileIds !== [] && Hilos::hasFeature(HilosFeature::FILES)) {
                    Database::afterCommit(function () use ($erasure): void {
                        try {
                            (Hilos::$files ?? throw new LogicException('The files door is not created'))->remove($erasure->fileIds);
                        } catch (HilosException $e) {
                            $this->logAgentError("Account of user {$this->userId} erased, but removing its files failed: {$e->getMessage()}");
                        }
                    });
                }

                return AccountErasureProjectResult::payloadOf($erasure);
            },
        );
    }

    /**
     * Ends the person's account as the agent's final in its erasure (HIL-1942), then stops the agent;
     * a repeat answers the saved final and stops the agent again.
     *
     * Called by the agent in its own frame with the final assignment the coordinator wrote. Before any
     * work the assignment must be an erasure's, the person's own final, and name this agent's account;
     * every part of the account must have its result, no wait for an owner may stand, and no account
     * may still be folded into this one. Each refusal writes nothing. Inside the step's transaction the
     * final completes the deletion request and deletes the merge row, the rename journal, the capture
     * and the person's row, and records which request it completed ({@see AccountErasurePersonalFinal}).
     * The stop is asked after the step returned, outside its transaction, so it follows only a
     * committed or saved final; a refusal leaves the agent running.
     *
     * SCAFFOLD: no live caller until the erasure coordinator delivers the final (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $assignmentId The person's final the coordinator gave this agent
     * @return AccountOperationResult Committed final or the saved outcome of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws ValidationException When the operation is no erasure, the assignment is not this person's final, or the
     *     deletion request cannot be completed
     * @throws AccountErasureFinalWaitsException When a part of the account has no result, a wait for an owner stands,
     *     or another account is still folded into this one
     * @throws AccountErasureFinalRefusedException When a row of another table still references the person; nothing is written
     * @throws Throwable When storage or a truth-source guard fails, preserving the original failure
     */
    public function finishAccountErasure(int $assignmentId): AccountOperationResult
    {
        $result = AccountErasurePersonalFinal::run($assignmentId, $this->userId);
        $this->selfStop();

        return $result;
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
     * of a trust into its log. A standing trust extended is answered the same way, with whether the
     * pair stood (HIL-1907). A notification marked read, a channel turned on or off, and a push
     * subscription dropped or a device removed are answered the same way (HIL-1408): the notifications
     * library waits on the answer, and the tabs hear a read or a channel change only after the write.
     * A push endpoint another person subscribes is released and answered to that library too, by a
     * folded account as well (HIL-1906). The stored trust restrictions are applied to the person's
     * trusts and answered to whichever agent asked, under the reply name its frame carries, by a
     * folded account as well (HIL-1900). The unfinished enrolments of the second factor are dropped
     * on the library's frame before it creates the next one (HIL-1903), and so is the set of backup codes
     * (HIL-1904).
     * A standing deletion canceled, and a profile photo set or removed, are answered the same way
     * (HIL-1409): the users library waits on the answer. The sessions library reports the last zone
     * a browser named in the background (HIL-1582). That report, the provider-name report and the
     * transport's dead-endpoint report are not answered; their refusals go to this agent's log.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one of this agent's frames
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws AgentException When the frame names another person
     * @throws InvalidArgumentException When the answer frame cannot be named or queued
     * @throws InvalidFormatException When a renewal answer is both renewed and refused
     * @throws DatabaseException When the folded-account lookup fails
     * @throws LogicException When the folded-account collection cannot be read
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
                if (!$data->data instanceof UserLastBrowserTimeZoneWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserLastBrowserTimeZoneWriteSignalData::class, $data->data);
                }

                $this->handleLastBrowserTimeZoneWrite($data->data, $name);

                return;

            case HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE:
                if (!$data->data instanceof UserIdentityProviderNameWriteSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserIdentityProviderNameWriteSignalData::class, $data->data);
                }

                $this->handleIdentityProviderNameWrite($data->data, $name);

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

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_DROP:
                $enrollDrop = $data->data;
                if (!$enrollDrop instanceof UserSecondFactorEnrollDropSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorEnrollDropSignalData::class, $enrollDrop);
                }

                $this->refuseAnotherPerson($enrollDrop->userId, $name);
                $this->sendToAgent($enrollDrop->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->dropEnrolment($enrollDrop),
                    fn (ActionRefusal $refusal) => UserSecondFactorEnrollDropDoneSignalData::refused($enrollDrop, $refusal),
                ));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_DROP:
                $codesDrop = $data->data;
                if (!$codesDrop instanceof UserSecondFactorCodesDropSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorCodesDropSignalData::class, $codesDrop);
                }

                $this->refuseAnotherPerson($codesDrop->userId, $name);
                $this->sendToAgent($codesDrop->replySignal, $this->secondFactorAnswer(
                    fn () => $this->secondFactorEdits()->dropCodes($codesDrop),
                    fn (ActionRefusal $refusal) => UserSecondFactorCodesDropDoneSignalData::refused($codesDrop, $refusal),
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

            case HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET:
                $ask = $data->data;
                if (!$ask instanceof UserLegalHoldForgetSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserLegalHoldForgetSignalData::class, $ask);
                }

                $this->refuseAnotherPerson($ask->userId, $name);
                $this->sendToAgent($ask->replySignal, $this->legalHoldForgetAnswer($ask));
                if (isset(Hilos::$db->userMerges[$this->userId])) {
                    $this->selfStop();
                }

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

            case HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW:
                $trustRenew = $data->data;
                if (!$trustRenew instanceof UserBrowserTrustRenewSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserBrowserTrustRenewSignalData::class, $trustRenew);
                }

                $this->refuseAnotherPerson($trustRenew->userId, $name);
                $renewed = false;
                $refusal = $this->flagRefusal(function () use ($trustRenew, &$renewed): void {
                    $renewed = $this->renewBrowserTrust($trustRenew);
                }, 'Browser trust renewal');
                $this->sendToAgent($trustRenew->replySignal, UserBrowserTrustRenewDoneSignalData::to($trustRenew, $renewed, $refusal));

                return;

            case HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT:
                $restrict = $data->data;
                if (!$restrict instanceof UserSecondFactorTrustRestrictSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserSecondFactorTrustRestrictSignalData::class, $restrict);
                }

                $this->refuseAnotherPerson($restrict->userId, $name);
                $this->sendToAgent($restrict->replySignal, $this->restrictTrustsAnswer($restrict));
                if (isset(Hilos::$db->userMerges[$this->userId])) {
                    $this->selfStop();
                }

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

            case HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE:
                $pushSubscribe = $data->data;
                if (!$pushSubscribe instanceof UserPushSubscribeSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPushSubscribeSignalData::class, $pushSubscribe);
                }

                $this->refuseAnotherPerson($pushSubscribe->userId, $name);
                $this->sendToAgent($pushSubscribe->replySignal, HandoverAnswerSignalData::to(
                    $pushSubscribe,
                    $this->flagRefusal(fn () => $this->subscribePush($pushSubscribe), 'Push subscribe'),
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

            case HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE:
                $gone = $data->data;
                if (!$gone instanceof UserPushEndpointGoneSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPushEndpointGoneSignalData::class, $gone);
                }

                $this->refuseAnotherPerson($gone->userId, $name);
                $this->applyPushEndpointGone($gone);
                if (isset(Hilos::$db->userMerges[$this->userId])) {
                    $this->selfStop();
                }

                return;

            case HilosSignalConstants::HILOS_USER_PUSH_RELEASE:
                $release = $data->data;
                if (!$release instanceof UserPushReleaseSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserPushReleaseSignalData::class, $release);
                }

                $this->refuseAnotherPerson($release->userId, $name);
                $this->sendToAgent($release->replySignal, $this->releasePushAnswer($release));
                if (isset(Hilos::$db->userMerges[$this->userId])) {
                    $this->selfStop();
                }

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

    /**
     * Applies the stored trust restrictions to the person's trusts when the agent starts (HIL-1901).
     *
     * An owner that starts finishes what is owed to it from the facts, not from the next frame
     * (Recovery, docs/agents/architecture/account-operations.md): a restriction saved while this
     * agent slept is applied here, and the frame that woke it then finds nothing left to change.
     * One count asks whether anything is owed, and only then does a transaction open. Nobody waits
     * on a start, so nothing is answered. A folded account applies it too - service work on the rows
     * it still has - and stops by its ordinary ways. A failure is logged, naming the person and no
     * browser's session, and the start goes on: the session holder's sweep asks again.
     */
    public function onStart(): void
    {
        try {
            if (Hilos::$db->secondFactorTrusts->getObjectCollection()->owes($this->userId)) {
                Hilos::$db->secondFactorTrusts->actions->applySavedRestrictions($this->userId);
            }
        } catch (WiringRefusal $e) {
            $this->logAgentError("Trust restrictions of #{$this->userId} not applied at start: {$e->getMessage()}");
        } catch (Throwable $e) {
            $this->logAgentError("Trust restrictions of #{$this->userId} not applied at start: {$e->getMessage()}");
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
     * so the new one ends that trust everywhere but in the browser that set it. Taking the trust
     * away commits first in its own transaction; the hash and its change moment then commit together
     * in a separate transaction. The framework does not nest them. A failure of either phase refuses
     * the password as not changed, and the password is never new while the other browsers still pass
     * without a code. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param int $identityId Password row to write
     * @param string $passwordHash `password_hash()` value to store
     * @param int $keepSessionId Session row of the browser that set the password, which keeps its trust; 0 when it has none
     * @throws ValidationException When the row is not this person's password
     * @throws LogicException When the identities collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When the row cannot be loaded, or the secret cannot be written
     * @throws HilosException On database, truth-source or transaction failure while taking the trust away or writing the row
     */
    protected function writeNewPassword(int $identityId, string $passwordHash, int $keepSessionId): void
    {
        Hilos::$db->secondFactorTrusts->actions->deleteForUserExceptSession($this->userId, $keepSessionId);
        Database::transactionStart();
        try {
            $this->writePasswordHash($identityId, $passwordHash);
            Hilos::$db->identities[$identityId]->actions->recordPasswordChange();
            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
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
     * Extends the standing trust of a browser the sessions library found at a confirmation (HIL-1907).
     *
     * The pair takes the end and the grant the frame names - the moment the second factor was
     * confirmed, not the moment of this write - and the stored restrictions are applied to the
     * person in the same transaction. A pair granted later already is left as it is; a missing pair
     * is not created, the library creates it. Not final, for the reason {@see self::renamePerson()} gives.
     *
     * @param UserBrowserTrustRenewSignalData $ask The library's request: the browser, the new end and the grant
     * @return bool Whether the pair stands - extended now or granted later already; false when there is none
     * @throws HilosException On truth-source, lookup, transaction or write failure while extending the trust
     */
    protected function renewBrowserTrust(UserBrowserTrustRenewSignalData $ask): bool
    {
        return Hilos::$db->secondFactorTrusts->actions->renew($ask->sessionId, $this->userId, $ask->trustedUntil, $ask->grantedAt);
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
     * Renews the captured device without following its endpoint to a replacement row.
     *
     * @param UserPushSubscribeSignalData $ask Captured device and subscription keys
     * @throws HilosException When the device is gone or the write cannot be persisted
     */
    protected function subscribePush(UserPushSubscribeSignalData $ask): void
    {
        $subscription = Hilos::$db->pushSubscriptions[$ask->subscriptionId] ?? null;
        if ($subscription === null || !$subscription->actions->renewOwned(
            $this->userId,
            $ask->endpoint,
            $ask->p256dh,
            $ask->auth,
            $ask->userAgent,
        )) {
            throw new ValidationException('This device is already gone');
        }
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
     * What the project keeps beside the person's addresses in the erasure capture, for its own
     * owners that delete after the ways in are gone (HIL-1940).
     *
     * Called inside the capture's transaction; it opens none of its own. A throw rolls the whole
     * capture back: nothing it wrote stays, and no result is recorded.
     *
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $userId Person whose account is being erased
     * @return array<string, mixed> Project's keys of the capture's `project` object; none by default
     * @throws HilosException Whatever the project's part raises
     */
    protected function erasureCaptureProjectPart(int $userId): array
    {
        return [];
    }

    /**
     * Deletes the person's child sets inside the erasure part's transaction (HIL-1941).
     *
     * The device keys go before the ways in, which they restrict the delete of; then the second
     * factor, the confirmations and the legal acceptances; then, only where the project activated
     * each table, the notifications, the channel choices, the push subscriptions and, last, the photo
     * row. The photo's registry file is named, not removed: a file deleted from disk cannot come
     * back if the transaction rolls back. The person's own row, rename journal and merge row are not
     * this part's (HIL-1942).
     *
     * Not final: the framework's test stands replace it to make the part fail.
     *
     * SCAFFOLD: no live caller until the erasure coordinator delivers the assignment (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $userId Person whose account is being erased
     * @return AccountErasure The photo row and its registry file, or nothing when there was no photo or photos are not activated
     * @throws DatabaseException When a lookup or a delete fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When this agent's claims do not cover a removal
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When a collection's class constants are not configured
     * @throws DbCollectionNotReadableException When this process does not read a collection asked about its activation
     * @throws NamedDatabaseException When a collection's database binding cannot be resolved
     * @throws HilosException When a removal is refused or fails in a collection that names no narrower failure
     */
    protected function erasePersonalChildren(int $userId): AccountErasure
    {
        // The device keys before the ways in: a credential restricts the delete of its anchor.
        Hilos::$db->passkeyCredentials->deleteForUser($userId);
        Hilos::$db->identities->deleteForUser($userId);
        Hilos::$db->secondFactors->actions->deleteForUser($userId);
        Hilos::$db->secondFactorBackupCodes->actions->deleteForUser($userId);
        Hilos::$db->secondFactorResets->actions->deleteForUser($userId);
        Hilos::$db->secondFactorSettings->actions->deleteForUser($userId);
        Hilos::$db->secondFactorTrusts->actions->deleteForUser($userId);
        Hilos::$db->stepUps->actions->deleteForUser($userId);
        Hilos::$db->legalAcceptances->actions->deleteForUser($userId);
        if (AccountOperationActivation::anyActivated([HilosDbContext::notifications])) {
            Hilos::$db->notifications->actions->deleteForUser($userId);
        }
        if (AccountOperationActivation::anyActivated([HilosDbContext::notificationPreferences])) {
            Hilos::$db->notificationPreferences->actions->deleteForUser($userId);
        }
        if (AccountOperationActivation::anyActivated([HilosDbContext::pushSubscriptions])) {
            Hilos::$db->pushSubscriptions->actions->deleteForUser($userId);
        }
        $fileId = AccountOperationActivation::anyActivated([HilosDbContext::userPhotos])
            ? Hilos::$db->userPhotos->actions->deleteForUser($userId)
            : null;

        return new AccountErasure(
            $fileId === null ? [] : [EntityUserPhoto::_table => 1],
            $fileId === null ? [] : [$fileId],
        );
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
     * A project's frame checks its addressee by the same words.
     *
     * @param int $userId Person the frame names
     * @param string $name Frame name, for the refusal
     * @throws AgentException When the frame names another person than this agent's
     */
    protected function refuseAnotherPerson(int $userId, string $name): void
    {
        if ($userId !== $this->userId) {
            throw new AgentException("{$name} for user #{$userId} reached the agent of user #{$this->userId}");
        }
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
     * Writes the transport's report without an answer, including for a folded account; refusals are logged here.
     *
     * @param UserPushEndpointGoneSignalData $report Captured subscription and its revision
     */
    private function applyPushEndpointGone(UserPushEndpointGoneSignalData $report): void
    {
        try {
            Hilos::$db->pushSubscriptions->actions->markGoneOwned($this->userId, $report->subscriptionId, $report->revision);
        } catch (WiringRefusal $wiring) {
            $this->logAgentError("Push device #{$report->subscriptionId} for #{$this->userId} refused by wiring: {$wiring->getMessage()}");
        } catch (Throwable $e) {
            $this->logAgentError("Push device #{$report->subscriptionId} for #{$this->userId} not marked gone: {$e->getMessage()}");
        }
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
     * Keeps provider metadata on the captured row only; a stale id never resolves to a replacement.
     *
     * A changed name rides the incoming receipt or opens an autonomous agent receipt. A refusal is
     * logged here and cannot change the completed sign-in. Blank and repeated names write nothing.
     *
     * @param UserIdentityProviderNameWriteSignalData $report Captured identity and provider fact
     * @param string $name Frame name, also the autonomous journal action
     * @throws AgentException When the frame names another person
     */
    private function handleIdentityProviderNameWrite(UserIdentityProviderNameWriteSignalData $report, string $name): void
    {
        $this->refuseAnotherPerson($report->userId, $name);
        try {
            AddressablePerson::require($this->userId);
            if (!isset(Hilos::$db->identities[$report->identityId])
                || Hilos::$db->identities[$report->identityId]->userId !== $this->userId
                || Hilos::$db->identities[$report->identityId]->type !== IdentityType::OAUTH) {
                throw new ValidationException('The OAuth sign-in method no longer belongs to this person.');
            }

            $providerName = trim($report->providerName);
            if ($providerName === '' || $providerName === Hilos::$db->identities[$report->identityId]->providerName) {
                return;
            }

            if (JournalReceiptScope::currentId() === null) {
                JournalReceiptScope::run(
                    JournalReceiptData::agent($name, $this->getId()),
                    fn () => Hilos::$db->identities[$report->identityId]->actions->rememberProviderName($providerName),
                );
            } else {
                Hilos::$db->identities[$report->identityId]->actions->rememberProviderName($providerName);
            }
        } catch (WiringRefusal $wiring) {
            $this->logAgentError("OAuth provider name for #{$this->userId} refused by wiring: {$wiring->getMessage()}");
        } catch (Throwable $e) {
            $this->logAgentError("OAuth provider name for #{$this->userId} not written: {$e->getMessage()}");
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
     * Removes later acceptances for a test hold, including for a folded account.
     *
     * @param UserLegalHoldForgetSignalData $ask The document, held revision and parked command
     * @return UserLegalHoldForgetDoneSignalData Removal outcome carrying the unchanged request
     */
    private function legalHoldForgetAnswer(UserLegalHoldForgetSignalData $ask): UserLegalHoldForgetDoneSignalData
    {
        try {
            if (!isset(Hilos::$db->users[$this->userId])) {
                throw new ItemNotFoundForUpdateException("No such user: {$this->userId}");
            }
            $document = LegalDocument::tryFrom($ask->document);
            if ($document === null) {
                throw new ValidationException("Legal document {$ask->document} is not declared in this installation");
            }

            Database::transactionStart();
            try {
                Hilos::$db->legalAcceptances->actions->forgetLaterThan($this->userId, $document, $ask->revisionId);
                Database::transactionCommit();
            } catch (WiringRefusal $e) {
                Database::transactionRollback();
                throw $e;
            } catch (Throwable $e) {
                Database::transactionRollback();
                throw $e;
            }

            return UserLegalHoldForgetDoneSignalData::ok($ask);
        } catch (ValidationException $e) {
            return UserLegalHoldForgetDoneSignalData::refused($ask, ActionRefusal::said($e->getMessage()));
        } catch (WiringRefusal $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("Legal hold of #{$this->userId} failed: {$e->getMessage()}");
            }

            return UserLegalHoldForgetDoneSignalData::refused($ask, $refusal);
        } catch (Throwable $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("Legal hold of #{$this->userId} failed: {$e->getMessage()}");
            }

            return UserLegalHoldForgetDoneSignalData::refused($ask, $refusal);
        }
    }

    /**
     * Releases a push endpoint another person subscribes, including for a folded account (HIL-1906).
     *
     * The row goes only while it still has this person and the asked endpoint; a missing or changed
     * row is released already, so every outcome but a failed write answers ok. The log names the
     * device by its id alone: the endpoint and keys are the browser's secrets.
     *
     * @param UserPushReleaseSignalData $ask The captured device and the waiting subscribe
     * @return UserPushReleaseDoneSignalData Release outcome carrying the unchanged request
     */
    private function releasePushAnswer(UserPushReleaseSignalData $ask): UserPushReleaseDoneSignalData
    {
        try {
            Hilos::$db->pushSubscriptions->actions->releaseOwned($this->userId, $ask->subscriptionId, $ask->endpoint);

            return UserPushReleaseDoneSignalData::ok($ask);
        } catch (ValidationException $e) {
            return UserPushReleaseDoneSignalData::refused($ask, ActionRefusal::said($e->getMessage()));
        } catch (WiringRefusal $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("Push device #{$ask->subscriptionId} of #{$this->userId} not released: {$e->getMessage()}");
            }

            return UserPushReleaseDoneSignalData::refused($ask, $refusal);
        } catch (Throwable $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("Push device #{$ask->subscriptionId} of #{$this->userId} not released: {$e->getMessage()}");
            }

            return UserPushReleaseDoneSignalData::refused($ask, $refusal);
        }
    }

    /**
     * Applies the stored trust restrictions to the person's trusts, including for a folded account (HIL-1900).
     *
     * A service frame: neither the person's standing nor a fold refuses it, and every outcome is
     * answered. A restriction that is not stored is refused in its own words, with nothing written;
     * any other failure takes every row back and is refused as a failure. The log names the person
     * and the restriction alone, never a browser's session.
     *
     * @param UserSecondFactorTrustRestrictSignalData $ask The restriction asked for and the reply name
     * @return UserSecondFactorTrustRestrictDoneSignalData Application outcome carrying the unchanged request
     */
    private function restrictTrustsAnswer(UserSecondFactorTrustRestrictSignalData $ask): UserSecondFactorTrustRestrictDoneSignalData
    {
        try {
            Hilos::$db->secondFactorTrusts->actions->applyRestrictions($this->userId, $ask->restrictionId);

            return UserSecondFactorTrustRestrictDoneSignalData::ok($ask);
        } catch (ValidationException $e) {
            return UserSecondFactorTrustRestrictDoneSignalData::refused($ask, ActionRefusal::said($e->getMessage()));
        } catch (WiringRefusal $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("Trust restriction #{$ask->restrictionId} not applied to #{$this->userId}: {$e->getMessage()}");
            }

            return UserSecondFactorTrustRestrictDoneSignalData::refused($ask, $refusal);
        } catch (Throwable $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("Trust restriction #{$ask->restrictionId} not applied to #{$this->userId}: {$e->getMessage()}");
            }

            return UserSecondFactorTrustRestrictDoneSignalData::refused($ask, $refusal);
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
}
