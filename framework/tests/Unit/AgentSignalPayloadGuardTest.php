<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\DTO\AuthCodeMintSignalData;
use Hilos\Auth\Code\DTO\AuthCodeMintedSignalData;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldExpiredSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldReleaseSignalData;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleSuccessSignalData;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteDoneSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\Upload\DTO\UploadHoldSettleSignalData;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsIndexWatchSignalData;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Pages\AbstractHilosSettingsPage;
use Hilos\Pages\Appearance\AbstractHilosAppearancePage;
use Hilos\Pages\Backup\AbstractHilosBackupPage;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;
use Hilos\Pages\Legal\AbstractHilosLegalSettingsPage;
use Hilos\Pages\Logs\AbstractHilosLogsSettingsPage;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Security\AbstractHilosSecurityImpersonationPage;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthPage;
use Hilos\Pages\Security\AbstractHilosSecuritySignInMethodsPage;
use Hilos\Pages\Security\AbstractHilosSecurityStepUpPage;
use Hilos\Pages\Security\AbstractHilosSecurityTwoFactorPage;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Pages\Users\AbstractHilosUserSessionsPage;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlDoneSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenanceDisableSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenanceEnableSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenancePassDoneSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenancePassSignalData;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\StatusAgent;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\AccountAdminSetSignalData;
use Hilos\Users\DTO\AccountBlockSetSignalData;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Hilos\Users\DTO\AdminRenameSignalData;
use Hilos\Users\DTO\UserAccountDeletionCancelDoneSignalData;
use Hilos\Users\DTO\UserAddressVerifyDoneSignalData;
use Hilos\Users\DTO\UserEmailChangeDoneSignalData;
use Hilos\Users\DTO\UserIdentityProviderNameWriteSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkDoneSignalData;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserPasskeyUseDoneSignalData;
use Hilos\Users\DTO\UserPasswordChangeDoneSignalData;
use Hilos\Users\DTO\UserIdentityAddDoneSignalData;
use Hilos\Core\Agent\DTO\AgentsGoneSignalData;
use Hilos\Users\DTO\UserPasswordRehashDoneSignalData;
use Hilos\Users\DTO\UserPasswordResetDoneSignalData;
use Hilos\Users\DTO\UserPhotoPutDoneSignalData;
use Hilos\Users\DTO\UserPhotoRemoveDoneSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorCodesIssueDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRequestDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollStartDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorProveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorWaitWriteDoneSignalData;
use Hilos\Users\DTO\UserStepUpRecordDoneSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Direct delivery must refuse a foreign DTO before any business operation or context access. */
final class AgentSignalPayloadGuardTest extends TestCase
{
    /**
     * Expectations come from the existing agent and page signal topology.
     *
     * @return array<string, array{class-string<AbstractAgent|AbstractPage>, string, class-string<SignalDataInterface>}> Named routes
     */
    public static function signalPayloads(): array
    {
        return [
            'HILOS_AUTH_CODE_SEND' => [
                AuthCodeAgent::class,
                HilosSignalConstants::HILOS_AUTH_CODE_SEND,
                AuthCodeSendSignalData::class,
            ],
            'HILOS_AUTH_CODE_MINTED' => [
                AuthCodeAgent::class,
                HilosSignalConstants::HILOS_AUTH_CODE_MINTED,
                AuthCodeMintedSignalData::class,
            ],
            'HILOS_ACCOUNT_ADMIN_SET' => [
                PayloadGuardSessionsLibraryAgent::class,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET,
                AccountAdminSetSignalData::class,
            ],
            'HILOS_ACCOUNT_BLOCK_SET' => [
                PayloadGuardSessionsLibraryAgent::class,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET,
                AccountBlockSetSignalData::class,
            ],
            'HILOS_AUTH_REGISTRATION_HOLD_EXPIRED' => [
                PayloadGuardSessionsLibraryAgent::class,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_EXPIRED,
                AuthRegistrationHoldExpiredSignalData::class,
            ],
            'HILOS_AUTH_CODE_MINT' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_AUTH_CODE_MINT,
                AuthCodeMintSignalData::class,
            ],
            'HILOS_AUTH_REGISTRATION_HOLD_RELEASE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_RELEASE,
                AuthRegistrationHoldReleaseSignalData::class,
            ],
            'HILOS_ACCOUNT_DELETION_SET' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET,
                AccountDeletionSetSignalData::class,
            ],
            'HILOS_USER_ADMIN_RENAME' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME,
                AdminRenameSignalData::class,
            ],
            'HILOS_USER_RENAME_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_RENAME_DONE,
                UserRenameDoneSignalData::class,
            ],
            'HILOS_USER_PASSWORD_REHASH_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_PASSWORD_REHASH_DONE,
                UserPasswordRehashDoneSignalData::class,
            ],
            'HILOS_USER_ADDRESS_VERIFY_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE,
                UserAddressVerifyDoneSignalData::class,
            ],
            'HILOS_USER_PASSKEY_USE_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE,
                UserPasskeyUseDoneSignalData::class,
            ],
            'HILOS_USER_PASSWORD_RESET_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE,
                UserPasswordResetDoneSignalData::class,
            ],
            'HILOS_USER_PASSWORD_CHANGE_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE,
                UserPasswordChangeDoneSignalData::class,
            ],
            'HILOS_USER_IDENTITY_ADD_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE,
                UserIdentityAddDoneSignalData::class,
            ],
            'HILOS_USER_EMAIL_CHANGE_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_EMAIL_CHANGE_DONE,
                UserEmailChangeDoneSignalData::class,
            ],
            'HILOS_USER_IDENTITY_UNLINK_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE,
                UserIdentityUnlinkDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_PROVE_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE,
                UserSecondFactorProveDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE,
                UserSecondFactorEnrollConfirmDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_REMOVE_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE,
                UserSecondFactorRemoveDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE,
                UserSecondFactorResetCancelDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE,
                UserSecondFactorWaitWriteDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_ENROLL_START_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START_DONE,
                UserSecondFactorEnrollStartDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_CODES_ISSUE_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_ISSUE_DONE,
                UserSecondFactorCodesIssueDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_RESET_REQUEST_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REQUEST_DONE,
                UserSecondFactorResetRequestDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE,
                UserSecondFactorResetDueDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE,
                UserSecondFactorResetRemindDoneSignalData::class,
            ],
            'HILOS_USER_SECOND_FACTOR_UNLOCK_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK_DONE,
                UserSecondFactorUnlockDoneSignalData::class,
            ],
            'HILOS_USER_STEP_UP_RECORD_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_STEP_UP_RECORD_DONE,
                UserStepUpRecordDoneSignalData::class,
            ],
            'HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE,
                UserAccountDeletionCancelDoneSignalData::class,
            ],
            'HILOS_USER_PHOTO_PUT_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE,
                UserPhotoPutDoneSignalData::class,
            ],
            'HILOS_USER_PHOTO_REMOVE_DONE' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE,
                UserPhotoRemoveDoneSignalData::class,
            ],
            'HILOS_MODERATION_VERDICT' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_MODERATION_VERDICT,
                ModerationVerdictSignalData::class,
            ],
            'HILOS_PROFILE_PHOTO_PUBLISHED' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED,
                FilesPublishedSignalData::class,
            ],
            'HILOS_OAUTH_LOGIN_READY' => [
                PayloadGuardUsersLibraryAgent::class,
                HilosSignalConstants::HILOS_OAUTH_LOGIN_READY,
                OAuthLoginReadySignalData::class,
            ],
            'HILOS_OAUTH_PENDING' => [
                PayloadGuardOAuthAgent::class,
                HilosSignalConstants::HILOS_OAUTH_PENDING,
                OAuthPendingLoginSignalData::class,
            ],
            'HILOS_OAUTH_IDENTITY_ADD_DONE' => [
                PayloadGuardOAuthAgent::class,
                HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE,
                UserIdentityAddDoneSignalData::class,
            ],
            'HILOS_OAUTH_AGENTS_GONE' => [
                PayloadGuardOAuthAgent::class,
                HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE,
                AgentsGoneSignalData::class,
            ],
            'MAINTENANCE_AGENT_ENABLE' => [
                PayloadGuardHilosIndexAgent::class,
                HilosSignalConstants::MAINTENANCE_AGENT_ENABLE,
                ManualMaintenanceEnableSignalData::class,
            ],
            'MAINTENANCE_AGENT_DISABLE' => [
                PayloadGuardHilosIndexAgent::class,
                HilosSignalConstants::MAINTENANCE_AGENT_DISABLE,
                ManualMaintenanceDisableSignalData::class,
            ],
            'MAINTENANCE_AGENT_PASS' => [
                PayloadGuardHilosIndexAgent::class,
                HilosSignalConstants::MAINTENANCE_AGENT_PASS,
                ManualMaintenancePassSignalData::class,
            ],
            'HILOS_UPLOAD_HOLD_SETTLE' => [
                UploadsAgent::class,
                HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE,
                UploadHoldSettleSignalData::class,
            ],
            'HILOS_APPEARANCE_SETTING_WRITE_DONE' => [
                PayloadGuardHilosAppearancePage::class,
                HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'MAINTENANCE_ENABLE_DONE' => [
                PayloadGuardHilosMaintenancePage::class,
                HilosSignalConstants::MAINTENANCE_ENABLE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'MAINTENANCE_DISABLE_DONE' => [
                PayloadGuardHilosMaintenancePage::class,
                HilosSignalConstants::MAINTENANCE_DISABLE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'MAINTENANCE_PASS_DONE' => [
                PayloadGuardHilosMaintenancePage::class,
                HilosSignalConstants::MAINTENANCE_PASS_DONE,
                ManualMaintenancePassDoneSignalData::class,
            ],
            'MAINTENANCE_RESTORE_CONTROL_DONE' => [
                PayloadGuardHilosMaintenancePage::class,
                HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE,
                MaintenanceRestoreControlDoneSignalData::class,
            ],
            'HILOS_SETTING_WRITE_DONE' => [
                PayloadGuardHilosSettingsPage::class,
                HilosSignalConstants::HILOS_SETTING_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_LOGS_SETTINGS_PRESET_APPLY_DONE' => [
                PayloadGuardHilosLogsSettingsPage::class,
                HilosSignalConstants::HILOS_LOGS_SETTINGS_PRESET_APPLY_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_STEP_UP_OPERATIONS_WRITE_DONE' => [
                PayloadGuardHilosSecurityStepUpPage::class,
                HilosSignalConstants::HILOS_STEP_UP_OPERATIONS_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_OAUTH_REDIRECT_WRITE_DONE' => [
                PayloadGuardHilosSecurityOAuthPage::class,
                HilosSignalConstants::HILOS_OAUTH_REDIRECT_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_IMPERSONATION_SETTING_WRITE_DONE' => [
                PayloadGuardHilosSecurityImpersonationPage::class,
                HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_SIGN_IN_METHODS_WRITE_DONE' => [
                PayloadGuardHilosSecuritySignInMethodsPage::class,
                HilosSignalConstants::HILOS_SIGN_IN_METHODS_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_SECOND_FACTOR_SETTING_WRITE_DONE' => [
                PayloadGuardHilosSecurityTwoFactorPage::class,
                HilosSignalConstants::HILOS_SECOND_FACTOR_SETTING_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_USER_ADMIN_RENAME_DONE' => [
                PayloadGuardHilosUserPage::class,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_ACCOUNT_MERGE_DONE' => [
                PayloadGuardHilosUserPage::class,
                HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_ACCOUNT_ADMIN_SET_DONE' => [
                PayloadGuardHilosUserPage::class,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_ACCOUNT_BLOCK_SET_DONE' => [
                PayloadGuardHilosUserPage::class,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_ACCOUNT_DELETION_SET_DONE' => [
                PayloadGuardHilosUserPage::class,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_IMPERSONATE_DONE' => [
                PayloadGuardHilosUserPage::class,
                HilosSignalConstants::HILOS_IMPERSONATE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_USER_SESSIONS_END_DONE' => [
                PayloadGuardHilosUserSessionsPage::class,
                HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_BACKUP_DELETE_DONE' => [
                PayloadGuardHilosBackupPage::class,
                HilosSignalConstants::HILOS_BACKUP_DELETE_DONE,
                BackupDeleteDoneSignalData::class,
            ],
            'HILOS_LEGAL_SETTING_WRITE_DONE' => [
                PayloadGuardHilosLegalSettingsPage::class,
                HilosSignalConstants::HILOS_LEGAL_SETTING_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_DELIVERY_RETRY_DONE' => [
                PayloadGuardHilosCommunicationsDeliveriesPage::class,
                HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
                HandoverAnswerSignalData::class,
            ],
            'HILOS_CHANNEL_SETTING_WRITE_DONE' => [
                PayloadGuardHilosCommunicationsChannelPage::class,
                HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE,
                HandoverAnswerSignalData::class,
            ],
            'STATUS_DAEMON_PICTURE_PORTION' => [
                StatusAgent::class,
                HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION,
                DaemonClusterPicturePortionSignalData::class,
            ],
            'STATUS_CANCEL_PUBLICATION' => [
                StatusAgent::class,
                HilosSignalConstants::STATUS_CANCEL_PUBLICATION,
                StatusCancelPublicationSignalData::class,
            ],
            'STATUS_PUBLISH_NOW' => [
                StatusAgent::class,
                HilosSignalConstants::STATUS_PUBLISH_NOW,
                StatusPublishNowSignalData::class,
            ],
            'STATUS_ADD_UPDATE' => [
                StatusAgent::class,
                HilosSignalConstants::STATUS_ADD_UPDATE,
                StatusAddUpdateSignalData::class,
            ],
            'HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE' => [
                PayloadGuardUserAgent::class,
                HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE,
                UserLastBrowserTimeZoneWriteSignalData::class,
            ],
            'HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE' => [
                PayloadGuardUserAgent::class,
                HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE,
                UserIdentityProviderNameWriteSignalData::class,
            ],
            'HILOS_AUTH_THROTTLE_CHECK' => [
                AuthThrottleAgent::class,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK,
                ThrottleCheckSignalData::class,
            ],
            'HILOS_AUTH_THROTTLE_SUCCEEDED' => [
                AuthThrottleAgent::class,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED,
                ThrottleSuccessSignalData::class,
            ],
            'BACKUP_AGENT_CREATE' => [
                BackupAgent::class,
                HilosSignalConstants::BACKUP_AGENT_CREATE,
                BackupCreateSignalData::class,
            ],
            'BACKUP_AGENT_DELETE' => [
                BackupAgent::class,
                HilosSignalConstants::BACKUP_AGENT_DELETE,
                BackupDeleteSignalData::class,
            ],
            'BACKUP_AGENT_SET_KEEP' => [
                BackupAgent::class,
                HilosSignalConstants::BACKUP_AGENT_SET_KEEP,
                BackupSetKeepSignalData::class,
            ],
            'BACKUP_AGENT_RESTORE' => [
                BackupAgent::class,
                HilosSignalConstants::BACKUP_AGENT_RESTORE,
                BackupRestoreSignalData::class,
            ],
            'DAEMON_CLUSTER_PICTURE_PORTION' => [
                PayloadGuardHilosDaemonAgent::class,
                HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION,
                DaemonClusterPicturePortionSignalData::class,
            ],
            'LOGS_CLUSTER_INDEX_PORTION' => [
                PayloadGuardHilosLogsAgent::class,
                HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION,
                ClusterLogIndexPortionSignalData::class,
            ],
            'DAEMON_NODE_PICTURE_REPORT' => [
                DaemonCollectorAgent::class,
                HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT,
                DaemonNodePictureSignalData::class,
            ],
            'DAEMON_PICTURE_WATCH' => [
                DaemonCollectorAgent::class,
                HilosSignalConstants::DAEMON_PICTURE_WATCH,
                DaemonPictureWatchSignalData::class,
            ],
            'STATUS_DAEMON_PICTURE_WATCH' => [
                DaemonCollectorAgent::class,
                HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH,
                DaemonPictureWatchSignalData::class,
            ],
            'LOGS_NODE_INDEX_REPORT' => [
                LogAggregatorAgent::class,
                HilosSignalConstants::LOGS_NODE_INDEX_REPORT,
                NodeLogIndexSignalData::class,
            ],
            'LOGS_INDEX_WATCH' => [
                LogAggregatorAgent::class,
                HilosSignalConstants::LOGS_INDEX_WATCH,
                LogsIndexWatchSignalData::class,
            ],
            'HILOS_MAIL_SEND' => [
                MailDeliveryChannelAgent::class,
                HilosSignalConstants::HILOS_MAIL_SEND,
                MailSendSignalData::class,
            ],
            'HILOS_SMS_SEND' => [
                SmsDeliveryChannelAgent::class,
                HilosSignalConstants::HILOS_SMS_SEND,
                SmsSendSignalData::class,
            ],
        ];
    }

    /**
     * @param class-string<AbstractAgent|AbstractPage> $handlerClass Concrete production handler or thin inherited fixture
     * @param string $name Existing signal name
     * @param class-string<SignalDataInterface> $expectedClass DTO declared by the existing topology
     */
    #[DataProvider('signalPayloads')]
    public function testRejectsForeignPayloadBeforeBusinessWork(string $handlerClass, string $name, string $expectedClass): void
    {
        // Constructors need a running context; a refused payload must never reach that context.
        $handler = new ReflectionClass($handlerClass)->newInstanceWithoutConstructor();

        try {
            $handler->onSignalAgent(new AgentSignalData(new SignalData([])), '', $name);
            self::fail('The real handler must refuse the foreign payload');
        } catch (InvalidAgentSignalPayloadException $refusal) {
            self::assertSame(InvalidAgentSignalPayloadException::class, $refusal::class);
            self::assertStringContainsString($name, $refusal->getMessage());
            self::assertStringContainsString('expected ' . $expectedClass, $refusal->getMessage());
            self::assertStringContainsString('got ' . SignalData::class, $refusal->getMessage());
        }
    }
}

final class PayloadGuardSessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
}

final class PayloadGuardUsersLibraryAgent extends AbstractUsersLibraryAgent
{
}

final class PayloadGuardHilosIndexAgent extends AbstractHilosIndexAgent
{
}

final class PayloadGuardHilosAppearancePage extends AbstractHilosAppearancePage
{
}

final class PayloadGuardHilosMaintenancePage extends AbstractHilosMaintenancePage
{
}

final class PayloadGuardHilosSettingsPage extends AbstractHilosSettingsPage
{
}

final class PayloadGuardHilosLogsSettingsPage extends AbstractHilosLogsSettingsPage
{
}

final class PayloadGuardHilosSecurityStepUpPage extends AbstractHilosSecurityStepUpPage
{
}

final class PayloadGuardHilosSecurityOAuthPage extends AbstractHilosSecurityOAuthPage
{
}

final class PayloadGuardHilosSecurityImpersonationPage extends AbstractHilosSecurityImpersonationPage
{
}

final class PayloadGuardHilosSecuritySignInMethodsPage extends AbstractHilosSecuritySignInMethodsPage
{
}

final class PayloadGuardHilosSecurityTwoFactorPage extends AbstractHilosSecurityTwoFactorPage
{
}

final class PayloadGuardHilosUserPage extends AbstractHilosUserPage
{
}

final class PayloadGuardHilosUserSessionsPage extends AbstractHilosUserSessionsPage
{
}

final class PayloadGuardHilosBackupPage extends AbstractHilosBackupPage
{
}

final class PayloadGuardHilosLegalSettingsPage extends AbstractHilosLegalSettingsPage
{
}

final class PayloadGuardHilosCommunicationsDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
}

final class PayloadGuardHilosCommunicationsChannelPage extends AbstractHilosCommunicationsChannelPage
{
}

final class PayloadGuardUserAgent extends AbstractUserAgent
{
}

final class PayloadGuardHilosDaemonAgent extends AbstractHilosDaemonAgent
{
}

final class PayloadGuardHilosLogsAgent extends AbstractHilosLogsAgent
{
}

final class PayloadGuardOAuthAgent extends AbstractOAuthAgent
{
    protected function buildProviderRegistry(): OAuthProviderRegistry
    {
        return new OAuthProviderRegistry();
    }
}
