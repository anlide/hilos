<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosLegalAgentDaemon;
use Demo\Chat\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\Data\UserMergeBrowserData;
use Demo\Chat\Browser\Data\UserPresenceBrowserData;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Pages\UserPage as ChatUserPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\Chat\Pages\Hilos\Legal\LegalPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentPage;
use Demo\Chat\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Chat\Pages\Hilos\Legal\LegalAcceptancesPage;
use Demo\Chat\Pages\Hilos\Legal\LegalSettingsPage;
use Demo\Chat\Pages\Hilos\AppearancePage;
use Demo\Chat\Tables\HilosLegal\HilosLegalAcceptancesTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Hilos\Legal\Export\DTO\LegalAcceptancesExportForgetSignalData;
use Hilos\Legal\Export\LegalAcceptancesExportHttp;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;

use Hilos\DataExport\DataExportHttp;
use Hilos\DataExport\DTO\DataExportOrderActionDTO;
use Hilos\DataExport\DTO\DataExportForgetUserSignalData;
use Hilos\DataExport\DataExportAgentDaemon;
use Demo\Chat\Agents\Hilos\DataExportAgent;
use Closure;
use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\Hilos\UserAgent;
use Demo\Chat\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\CLI\ChatCliManager;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Agent\Daemon\BotAgentDaemon;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionCancelActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionCodeActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionOpenActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionStartActionDTO;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Auth\Library\DTO\AuthOtherSessionsEndSignalData;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationCanceledSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationProvenSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitHeldSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorCancelSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorMissedSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorOffSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorTrustDaysApplySignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorTrustRevokeOthersSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorSetupProvenSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\ProfileFlowStepSignalData;
use Hilos\Auth\Library\DTO\ProfilePhotoRemoveActionDTO;
use Hilos\Auth\Library\DTO\ProfilePhotoSetActionDTO;
use Hilos\Auth\Library\DTO\CancelRegistrationActionDTO;
use Hilos\Auth\Library\DTO\CancelSecondFactorActionDTO;
use Hilos\Auth\Library\DTO\CompletePasswordResetActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationPasskeyActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationPasswordlessActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkCodeActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\ConfirmSecondFactorActionDTO;
use Hilos\Auth\Library\DTO\DetectIdentifierActionDTO;
use Hilos\Auth\Library\DTO\LegalAcceptActionDTO;
use Hilos\Auth\Library\DTO\LegalConsentActionDTO;
use Hilos\Auth\Library\DTO\LegalReconsentActionDTO;
use Hilos\Auth\Library\DTO\LegalReconsentPreviewActionDTO;
use Hilos\Auth\Library\DTO\LinkOAuthAfterReauthActionDTO;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\Library\DTO\OAuthCallbackActionDTO;
use Hilos\Auth\Library\DTO\OAuthCreateAccountActionDTO;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\Library\DTO\OAuthStartActionDTO;
use Hilos\Auth\Library\DTO\PasskeyDiscoverableLoginOptionsActionDTO;
use Hilos\Auth\Library\DTO\PasskeyLoginConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterOptionsActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordOpenActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileSetPasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileUnlinkIdentityActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RegistrationPasskeyOptionsActionDTO;
use Hilos\Auth\Library\DTO\RequestMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\RequestPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\RequestRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorResetCancelLinkActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorResetRequestActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorSetupConfirmActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorSetupFinishActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorSetupStartActionDTO;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripEndedSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripOpenedSignalData;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesRenewActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesShowActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollConfirmActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollStartActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorRemoveActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetCancelActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetRequestActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetWaitSetActionDTO;
use Hilos\Auth\Session\DTO\AccountBlockChangedSignalData;
use Hilos\Auth\Session\DTO\BrowserEraseActionDTO;
use Hilos\Auth\Session\DTO\DeferredSessionCarryoverHandoverSignalData;
use Hilos\Auth\Session\DTO\DismissAccountBlockedActionDTO;
use Hilos\Auth\Session\DTO\DismissSessionAckActionDTO;
use Hilos\Auth\Session\DTO\DismissSessionToastActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateRequestSignalData;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Auth\Session\DTO\LogoutActionDTO;
use Hilos\Auth\Session\DTO\OAuthResumeActionDTO;
use Hilos\Auth\Session\DTO\ProfileFlowCancelActionDTO;
use Hilos\Auth\Session\DTO\RaiseSessionToastSignalData;
use Hilos\Auth\Session\DTO\SessionEndActionDTO;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\SessionToastExpiredActionDTO;
use Hilos\Auth\Session\DTO\SessionToastReadingActionDTO;
use Hilos\Auth\Session\DTO\SessionsEndOthersActionDTO;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\DTO\StepUpStartActionDTO;
use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleSuccessSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupReopenSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Backup\Agent\DTO\DeferredNoticesSentSignalData;
use Hilos\Backup\Agent\DTO\DeferredSessionsCarriedSignalData;
use Hilos\Backup\BackupConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\DTO\AgentsGoneSignalData;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Database\Settings\Library\DTO\SettingDeleteSignalData;
use Hilos\Database\Settings\Library\DTO\SettingPresetApplySignalData;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\Image\DTO\ImageRenderSignalData;
use Hilos\Files\Upload\DTO\UploadCancelActionDTO;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\DTO\FilePublishSignalData;
use Hilos\Files\Image\DTO\ImageRenderedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterCronSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterHttpSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterStandingSignalData;
use Hilos\DaemonSection\DTO\DaemonAgentCronSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsFollowStartSignalData;
use Hilos\Log\DTO\LogsFollowStopSignalData;
use Hilos\Log\DTO\LogsIndexWatchSignalData;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Log\DTO\LogsTakeoutConfirmSignalData;
use Hilos\Log\DTO\LogsTakeoutUndoSignalData;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Notification\DTO\DeferredNotificationHandoverSignalData;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\DTO\NotificationChannelPreferenceActionDTO;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\DTO\NotificationMarkAllReadPayloadDTO;
use Hilos\Notification\DTO\NotificationMarkReadPayloadDTO;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Pages\Logs\DTO\LogsFollowStartActionDTO;
use Hilos\Pages\Logs\DTO\LogsFollowStopActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmActionDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutUndoActionDTO;
use Hilos\Push\DTO\PushRemoveActionDTO;
use Hilos\Push\DTO\PushSubscribeActionDTO;
use Hilos\Push\DTO\PushSubscriptionsGoneSignalData;
use Hilos\Push\DTO\PushUnsubscribeActionDTO;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Users\DTO\AccountAdminSetSignalData;
use Hilos\Users\DTO\AccountBlockSetSignalData;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Hilos\Users\DTO\AccountMergeSignalData;
use Hilos\Users\DTO\AdminRenameSignalData;
use Hilos\Users\DTO\ProfilePhotoVerdictSignalData;
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteDoneSignalData;
use Hilos\Users\DTO\UserPasswordRehashSignalData;
use Hilos\Users\DTO\UserPasswordRehashDoneSignalData;
use Hilos\Users\DTO\UserAddressVerifySignalData;
use Hilos\Users\DTO\UserAddressVerifyDoneSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasskeyUseDoneSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Users\DTO\UserPasswordResetDoneSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserPasswordChangeDoneSignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserEmailChangeDoneSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkDoneSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserAdminWriteDoneSignalData;
use Hilos\Users\DTO\UserAdminCommandSignalData;
use Hilos\Users\DTO\UserAdminCommandDoneSignalData;
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
use Hilos\HilosException;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\Database\Schema\MountedCollectionKeyGuard;
use Demo\Chat\Pages\Hilos\I18nPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the project-level chat topology registry.
 */
final class ChatTopologyRegistryTest extends TestCase
{
    /** Pins the Daemon section's pages, owners and placement in the chat topology. */
    public function testDaemonSectionActivation(): void
    {
        self::assertContains(HilosFeature::DAEMON, Hilos::featuresOf(Hilos::class));

        foreach ([
            DaemonPage::class,
            DaemonWorkersPage::class,
            DaemonAgentsPage::class,
            DaemonCronPage::class,
            DaemonWebsocketsPage::class,
            DaemonHttpServerPage::class,
            DaemonEnvMismatchPage::class,
        ] as $page) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_DAEMON, Hilos::getPageRoutes()[$page::PAGE]);
        }
        self::assertSame(DaemonEnvPage::class, Hilos::PAGES[DaemonEnvPage::PAGE]);
        self::assertSame(HilosAgentType::HILOS_DAEMON_NODE, Hilos::getPageRoutes()[DaemonEnvPage::PAGE]);

        $pageAgent = Hilos::AGENTS[AgentType::HILOS_DAEMON];
        self::assertSame(DemoHilosDaemonAgent::class, AgentRegistry::workerClass($pageAgent));
        self::assertSame(DemoHilosDaemonAgentDaemon::class, AgentRegistry::daemonClass($pageAgent));
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($pageAgent));
        self::assertSame(AgentPlacement::LEADER, AgentRegistry::placement($pageAgent));
        self::assertFalse((new DemoHilosDaemonAgentDaemon())->requiresMonopolisticProcess());

        $nodeAgent = Hilos::AGENTS[HilosAgentType::HILOS_DAEMON_NODE];
        self::assertSame(DaemonNodeAgent::class, AgentRegistry::workerClass($nodeAgent));
        self::assertSame(DaemonNodeAgentDaemon::class, AgentRegistry::daemonClass($nodeAgent));
        self::assertSame(AgentScope::NODE, AgentRegistry::scope($nodeAgent));
        self::assertFalse((new DaemonNodeAgentDaemon())->requiresMonopolisticProcess());

        $collector = Hilos::AGENTS[HilosAgentType::HILOS_DAEMON_COLLECTOR];
        self::assertSame(DaemonCollectorAgent::class, AgentRegistry::workerClass($collector));
        self::assertSame(DaemonCollectorAgentDaemon::class, AgentRegistry::daemonClass($collector));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($collector));
        self::assertFalse((new DaemonCollectorAgentDaemon())->requiresMonopolisticProcess());
        self::assertSame(HilosDaemonCronTable::class, Hilos::TABLES[ChatTableContext::hilosDaemonCron]);
        self::assertSame(HilosDaemonWorkersTable::class, Hilos::TABLES[ChatTableContext::hilosDaemonWorkers]);
        self::assertSame(HilosDaemonAgentsTable::class, Hilos::TABLES[ChatTableContext::hilosDaemonAgents]);
        self::assertSame([ChatTableContext::hilosDaemonCron => []], Hilos::PAGE_TABLES[DaemonCronPage::PAGE]);
        self::assertSame([ChatTableContext::hilosDaemonWorkers => []], Hilos::PAGE_TABLES[DaemonWorkersPage::PAGE]);
        self::assertSame([ChatTableContext::hilosDaemonAgents => []], Hilos::PAGE_TABLES[DaemonAgentsPage::PAGE]);
    }

    /** The change-log agent serves both journal table viewports. */
    public function testChangeLogSectionTables(): void
    {
        self::assertSame(HilosChangeLogFeedTable::class, Hilos::TABLES[ChatTableContext::hilosChangeLogFeed]);
        self::assertSame(HilosChangeLogHistoryTable::class, Hilos::TABLES[ChatTableContext::hilosChangeLogHistory]);
        self::assertSame([ChatTableContext::hilosChangeLogFeed => []], Hilos::PAGE_TABLES[ChangeLogDashboardPage::PAGE]);
        self::assertSame([ChatTableContext::hilosChangeLogHistory => []], Hilos::PAGE_TABLES[ChangeLogTablePage::PAGE]);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogDashboardPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogTablePage::SUBSCRIPTION_AGENT_TYPE);
    }

    /** Appearance reuses the index agent and the framework's self-snapshot table. */
    public function testAppearanceAdminFeatureIsActivated(): void
    {
        self::assertSame(AppearancePage::class, Hilos::PAGES[AppearancePage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, AppearancePage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(
            HilosAppearanceSettingsTable::class,
            Hilos::TABLES[ChatTableContext::hilosAppearanceSettings],
        );
        self::assertSame(
            [ChatTableContext::hilosAppearanceSettings => []],
            Hilos::PAGE_TABLES[AppearancePage::PAGE],
        );
    }

    /** Legal administration is bound to its own worker, page routes and tables. */
    /**
     * The chat user page is drawn from two data sources over the same person (HIL-1292): the
     * runtime presence, and the merge the account may have gone into - the second bound to the
     * page by the same route parameter as the first.
     */
    public function testTheUserPageCarriesThePresenceAndTheMergeData(): void
    {
        $this->assertSame(UserPresenceBrowserData::class, Hilos::BROWSER_DATA[UserPresenceBrowserData::DATA]);
        $this->assertSame(UserMergeBrowserData::class, Hilos::BROWSER_DATA[UserMergeBrowserData::DATA]);
        $binding = [
            BrowserParamKey::PARAMS => [
                UserPageSubscribeParams::USER_ID => ChatBrowserRef::USER_ID,
            ],
        ];
        $this->assertSame(
            [
                UserPresenceBrowserData::DATA => $binding,
                UserMergeBrowserData::DATA => $binding,
            ],
            Hilos::PAGE_DATA[ChatUserPage::PAGE],
        );
    }

    public function testLegalAdministrationUsesItsOwnMonopolisticAgentAndFrameworkTables(): void
    {
        $this->assertSame(DemoHilosLegalAgent::class, Hilos::AGENTS[AgentType::HILOS_LEGAL][AgentRegistryKey::WORKER]);
        $this->assertSame(DemoHilosLegalAgentDaemon::class, Hilos::AGENTS[AgentType::HILOS_LEGAL][AgentRegistryKey::DAEMON]);
        $this->assertTrue(new DemoHilosLegalAgentDaemon()->requiresMonopolisticProcess());
        foreach ([
            LegalPage::class, LegalDocumentPage::class, LegalRevisionPage::class, LegalAcceptancesPage::class, LegalSettingsPage::class,
        ] as $page) {
            $this->assertSame($page, Hilos::PAGES[$page::PAGE]);
            $this->assertSame(AgentType::HILOS_LEGAL, $page::SUBSCRIPTION_AGENT_TYPE);
        }
        $this->assertSame(LegalSettingsPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::LEGAL_SETTING_SET]);
        $this->assertSame(AgentType::HILOS_LEGAL, Hilos::getActionAgentRoutes()[HilosSignalConstants::LEGAL_SETTING_SET]);
        $this->assertSame(LegalAcceptancesPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::LEGAL_ACCEPTANCES_EXPORT]);
        $this->assertSame(AgentType::HILOS_LEGAL, Hilos::getActionAgentRoutes()[HilosSignalConstants::LEGAL_ACCEPTANCES_EXPORT]);
        $this->assertSame([
            ChatTableContext::hilosLegalDocuments => [],
            ChatTableContext::hilosLegalChecks => [],
            ChatTableContext::hilosLegalSettings => [],
        ], Hilos::PAGE_TABLES[LegalPage::PAGE]);
        $this->assertSame([ChatTableContext::hilosLegalRevisions => []], Hilos::PAGE_TABLES[LegalDocumentPage::PAGE]);
        $this->assertSame([ChatTableContext::hilosLegalRevisions => []], Hilos::PAGE_TABLES[LegalRevisionPage::PAGE]);
        $this->assertSame([ChatTableContext::hilosLegalAcceptances => []], Hilos::PAGE_TABLES[LegalAcceptancesPage::PAGE]);
        $this->assertSame([ChatTableContext::hilosLegalSettings => []], Hilos::PAGE_TABLES[LegalSettingsPage::PAGE]);
        $catalog = SettingsCatalog::getCatalog();
        foreach (LegalSettings::KEYS as $key) {
            $this->assertSame(LegalSettingsCatalog::getCatalog()[$key], $catalog[$key]);
        }
        foreach (ThemeSettingsCatalog::KEYS as $key) {
            $this->assertSame(ThemeSettingsCatalog::getCatalog()[$key], $catalog[$key]);
        }
    }

    /** The export runs once on the policy-selected node and has a worker of its own. */
    public function testDataExportAgentIsPolicyPlacedAndMonopolistic(): void
    {
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_DATA_EXPORT];
        self::assertSame(DataExportAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(DataExportAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertTrue((new DataExportAgentDaemon())->requiresMonopolisticProcess());
    }

    protected function tearDown(): void
    {
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    /** The Daemon environment page names the node whose replica serves it. */
    public function testOnlyDaemonEnvironmentPageUsesNodeAddressedSubscription(): void
    {
        $routes = Hilos::getPageAgentIndexRoutes();
        $this->assertSame([DaemonEnvPage::PAGE], array_keys($routes));
        $this->assertSame('node_param', $routes[DaemonEnvPage::PAGE]->source->value);
        $this->assertSame('nodeId', $routes[DaemonEnvPage::PAGE]->param);
        $this->assertSame(HilosAgentType::HILOS_DAEMON, $routes[DaemonEnvPage::PAGE]->fallbackAgentType);
    }

    public function testComputedGroupRoutesCoverEveryRegisteredGroup(): void
    {
        $this->assertSame(array_keys(Hilos::GROUPS), array_keys(Hilos::getGroupRoutes()));
    }

    public function testRegistryValuesAreClassStrings(): void
    {
        foreach ([Hilos::PAGES, Hilos::GROUPS, Hilos::TABLES, $this->mergedBrowserSources()] as $registry) {
            foreach ($registry as $class) {
                $this->assertIsString($class);
                $this->assertTrue(class_exists($class), "{$class} must be a concrete class string");
            }
        }

        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $workerClass = AgentRegistry::workerClass($registryEntry);
            $daemonClass = AgentRegistry::daemonClass($registryEntry);

            $this->assertIsString($workerClass);
            $this->assertIsString($daemonClass);
            $this->assertTrue(class_exists($workerClass), "{$workerClass} must be a concrete worker class");
            $this->assertTrue(class_exists($daemonClass), "{$daemonClass} must be a concrete daemon class");
            $this->assertTrue(is_subclass_of($daemonClass, AbstractAgentDaemon::class));
            $this->assertSame($agentType, $workerClass::AGENT_TYPE);
        }
    }

    public function testPageRegistryKeysMatchPageClassConstants(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($page, $pageClass::PAGE);
        }
    }

    /** The person agent is addressed by id and sleeps after the standard idle window. */
    public function testPersonAgentHasTheIndexedPolicyRegistryEntry(): void
    {
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_USER];

        $this->assertSame(UserAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(UserAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(AgentRegistry::requiresIndex($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertSame(AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC, AgentRegistry::idleTimeout($entry));
        $daemon = new UserAgentDaemon('42');
        $this->assertSame('42', $daemon->getIndex());
        $this->assertFalse($daemon->requiresMonopolisticProcess());
    }

    public function testAgentRegistryKeysMatchAgentClassConstants(): void
    {
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $workerClass = AgentRegistry::workerClass($registryEntry);
            $this->assertNotNull($workerClass);
            $this->assertSame($agentType, $workerClass::AGENT_TYPE);
        }
    }

    /**
     * Both placement axes of every registered agent, so a declaration cannot change in silence.
     *
     * Written as the two exceptions rather than as eighteen rows: everything absent from both
     * lists is a cluster singleton hosted by the leader, which is what an entry declaring
     * neither axis means.
     */
    public function testEveryAgentDeclaresTheExpectedPlacementCell(): void
    {
        $nodeScoped = [];
        $policyPlaced = [];
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            if (AgentRegistry::scope($registryEntry) === AgentScope::NODE) {
                $nodeScoped[] = $agentType;
            }
            if (AgentRegistry::placement($registryEntry) === AgentPlacement::POLICY) {
                $policyPlaced[] = $agentType;
            }
        }

        // Node-local state, so one replica per node: the log directory itself, the analytics
        // journal of the node, the carrier that moves rotated log batches into the archive,
        // the code pool.
        $this->assertSame([
            HilosAgentType::HILOS_LOG_STORE,
            HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosAgentType::HILOS_LOG_CARRIER,
            HilosAgentType::HILOS_DAEMON_NODE,
            AgentType::HILOS_AUTH_CODE,
        ], $nodeScoped);

        // The six libraries, the backup agent, the delivery shards, the log aggregator and the auth throttle: one
        // instance cluster-wide (per shard index, for the shards), on the node policy picks. An
        // entity library is placed rather than pinned by rule, and each has a reason
        // of its own besides: minting an account is a claim one process holds wherever it sits,
        // every handshake touches sessions, every worker emits into notifications, every published
        // file is bound and swept by one owner, every admin screen writes settings through one
        // hand, and the i18n reference has one writer. The uploads and images agents are placed the same
        // way, beside the files library they hand files to through the cluster's tmp, on whatever
        // node each lands. The backup agent owns a directory on one node's disk, so it has to stay
        // with it: following
        // leadership would move it on every master restart to a node whose directory holds none
        // of its archives. The aggregator is placed so that one holder of the merged log picture
        // survives a re-election instead of dying with the term, and the analytics writer so that
        // one loader of the journals does, wherever it stands. The auth throttle is placed too: its
        // counters are one runtime collection for the whole cluster, so one agent counts them, and
        // pinning it to the leader would buy the guard nothing (HIL-1280).
        $this->assertSame([
            HilosAgentType::HILOS_DATA_EXPORT,
            HilosAgentType::HILOS_USER,
            HilosAgentType::HILOS_USERS_LIBRARY,
            HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosAgentType::HILOS_FILES_LIBRARY,
            HilosAgentType::HILOS_UPLOADS,
            HilosAgentType::HILOS_IMAGES,
            HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosAgentType::HILOS_I18N_LIBRARY,
            HilosAgentType::HILOS_BACKUP,
            AgentType::HILOS_MAIL,
            AgentType::HILOS_SMS,
            AgentType::HILOS_PUSH,
            HilosAgentType::HILOS_LOG_AGGREGATOR,
            HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosAgentType::HILOS_ANALYTICS_WRITER,
            AgentType::HILOS_AUTH_THROTTLE,
        ], $policyPlaced);
    }

    public function testBotAgentRegistryRequiresIndex(): void
    {
        $botEntry = Hilos::AGENTS[BotAgent::AGENT_TYPE] ?? null;

        $this->assertIsArray($botEntry);
        $this->assertTrue(AgentRegistry::requiresIndex($botEntry));
        $this->assertSame(BotAgent::class, AgentRegistry::workerClass($botEntry));
        $this->assertSame(BotAgentDaemon::class, AgentRegistry::daemonClass($botEntry));
        $this->assertFalse(AgentRegistry::requiresIndex(Hilos::AGENTS[AgentType::CHAT]));
    }

    /** The i18n section and five detail pages route to one placed framework library. */
    public function testI18nSectionUsesFrameworkLibrary(): void
    {
        $this->assertContains(HilosFeature::I18N, Hilos::featuresOf(Hilos::class));
        $this->assertSame([
            I18nPage::PAGE,
            LanguagesListPage::PAGE,
            CountriesListPage::PAGE,
            LanguageDetailPage::PAGE,
            LanguageNamesPage::PAGE,
            LanguageLocalesPage::PAGE,
            CountryDetailPage::PAGE,
            CountryNamesPage::PAGE,
        ], array_values(array_filter(
            array_keys(Hilos::PAGES),
            static fn (string $page): bool => str_starts_with($page, I18nPage::PAGE),
        )));

        foreach ([
            I18nPage::class, LanguagesListPage::class, CountriesListPage::class,
            LanguageDetailPage::class,
            LanguageNamesPage::class,
            LanguageLocalesPage::class,
            CountryDetailPage::class,
            CountryNamesPage::class,
        ] as $page) {
            $this->assertSame($page, Hilos::PAGES[$page::PAGE]);
            $this->assertSame(HilosAgentType::HILOS_I18N_LIBRARY, Hilos::getPageRoutes()[$page::PAGE]);
        }

        $this->assertSame(HilosI18nLanguageLocalesTable::class, Hilos::TABLES[ChatTableContext::hilosI18nLanguageLocales]);
        $this->assertSame([ChatTableContext::hilosI18nLanguageLocales => []], Hilos::PAGE_TABLES[LanguageLocalesPage::PAGE]);
        $this->assertSame(HilosI18nLanguagesTable::class, Hilos::TABLES[ChatTableContext::hilosI18nLanguages]);
        $this->assertSame([ChatTableContext::hilosI18nLanguages => []], Hilos::PAGE_TABLES[LanguagesListPage::PAGE]);
        $this->assertSame(LanguageCardBrowserData::class, Hilos::BROWSER_DATA[LanguageCardBrowserData::DATA]);
        $this->assertSame(
            [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            Hilos::PAGE_DATA[LanguageDetailPage::PAGE],
        );
        $this->assertSame(
            [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            Hilos::PAGE_DATA[LanguageNamesPage::PAGE],
        );
        $this->assertSame(
            [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            Hilos::PAGE_DATA[LanguageLocalesPage::PAGE],
        );
        $this->assertSame(CountryCardBrowserData::class, Hilos::BROWSER_DATA[CountryCardBrowserData::DATA]);
        $this->assertSame(
            [CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING],
            Hilos::PAGE_DATA[CountryDetailPage::PAGE],
        );
        $this->assertSame(
            [CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING],
            Hilos::PAGE_DATA[CountryNamesPage::PAGE],
        );

        $entry = Hilos::AGENTS[HilosAgentType::HILOS_I18N_LIBRARY];
        $this->assertSame(I18nLibraryAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(I18nLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertTrue((new I18nLibraryAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testPageSubscriptionOwnersAreDeclaredByPageClasses(): void
    {
        $pageRoutes = Hilos::getPageRoutes();

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($pageClass::SUBSCRIPTION_AGENT_TYPE, $pageRoutes[$page]);
            $this->assertNotSame('', $pageClass::SUBSCRIPTION_AGENT_TYPE, "{$page} must declare a subscription owner");
        }
    }

    public function testGroupRegistryKeysMatchGroupClassConstants(): void
    {
        foreach (Hilos::GROUPS as $group => $groupClass) {
            $this->assertSame($group, $groupClass::GROUP);
        }
    }

    public function testGroupSubscriptionOwnersAreDeclaredByGroupClasses(): void
    {
        $groupRoutes = Hilos::getGroupRoutes();

        foreach (Hilos::GROUPS as $group => $groupClass) {
            $this->assertSame($groupClass::SUBSCRIPTION_AGENT_TYPE, $groupRoutes[$group]);
            $this->assertNotSame('', $groupClass::SUBSCRIPTION_AGENT_TYPE, "{$group} must declare a subscription owner");
        }
    }

    public function testComputedPageActionRoutesMatchChatActionOwnership(): void
    {
        $this->assertSame([
            ChatSignalConstants::MESSAGE => PageConstants::MAIN,
            HilosSignalConstants::HILOS_LINK_OAUTH_START => HilosPageConstants::HILOS_PROFILE_SIGN_IN,
            ChatSignalConstants::BOT_CREATE => PageConstants::ADMIN_BOTS,
            ChatSignalConstants::BOT_UPDATE => PageConstants::ADMIN_BOTS,
            ChatSignalConstants::BOT_DELETE => PageConstants::ADMIN_BOTS,
            HilosSignalConstants::SETTING_ADD => PageConstants::HILOS_SETTINGS,
            HilosSignalConstants::SETTING_UPDATE => PageConstants::HILOS_SETTINGS,
            HilosSignalConstants::SETTING_DELETE => PageConstants::HILOS_SETTINGS,
            HilosSignalConstants::SETTING_RESET => PageConstants::HILOS_SETTINGS,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF => HilosPageConstants::HILOS_I18N_LANGUAGE,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_START => PageConstants::HILOS_GUARDIAN_AGENT,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => PageConstants::HILOS_GUARDIAN_AGENT,
            HilosSignalConstants::BACKUP_CREATE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_DELETE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_BULK_DELETE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_SET_KEEP => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_RESTORE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_REOPEN => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => HilosPageConstants::HILOS_MAINTENANCE,
            HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => HilosPageConstants::HILOS_MAINTENANCE,
            HilosSignalConstants::LOGS_TAKEOUT_CONFIRM => PageConstants::HILOS_LOGS_ROTATIONS,
            HilosSignalConstants::LOGS_TAKEOUT_UNDO => PageConstants::HILOS_LOGS_ROTATIONS,
            HilosSignalConstants::LOGS_READ_LINES => PageConstants::HILOS_LOGS_VIEW,
            HilosSignalConstants::LOGS_FOLLOW_START => PageConstants::HILOS_LOGS_VIEW,
            HilosSignalConstants::LOGS_FOLLOW_STOP => PageConstants::HILOS_LOGS_VIEW,
            HilosSignalConstants::SETTING_PRESET_APPLY => PageConstants::HILOS_LOGS_SETTINGS,
            HilosSignalConstants::HILOS_USER_UPDATE => PageConstants::HILOS_USER,
            HilosSignalConstants::HILOS_USER_MERGE => PageConstants::HILOS_USER,
            HilosSignalConstants::HILOS_USER_ADMIN_SET => PageConstants::HILOS_USER,
            HilosSignalConstants::HILOS_USER_BLOCK_SET => PageConstants::HILOS_USER,
            HilosSignalConstants::HILOS_USER_DELETION_SET => PageConstants::HILOS_USER,
            HilosSignalConstants::HILOS_IMPERSONATE_START => PageConstants::HILOS_USER,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY => PageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
            HilosSignalConstants::SECURITY_2FA_SETTING_SET => HilosPageConstants::HILOS_SECURITY_2FA,
            HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET => HilosPageConstants::HILOS_SECURITY_STEP_UP,
            HilosSignalConstants::LEGAL_ACCEPTANCES_EXPORT => HilosPageConstants::HILOS_LEGAL_ACCEPTANCES,
            HilosSignalConstants::LEGAL_SETTING_SET => HilosPageConstants::HILOS_LEGAL_SETTINGS,
            HilosSignalConstants::HILOS_LEGAL_REVISION_TEXT => HilosPageConstants::HILOS_PROFILE_AGREEMENTS_HISTORY,
            HilosSignalConstants::HILOS_LEGAL_REVISION_CHANGES => HilosPageConstants::HILOS_PROFILE_AGREEMENTS_HISTORY,
            HilosSignalConstants::SECURITY_OAUTH_REDIRECT_SET => PageConstants::HILOS_SECURITY_OAUTH,
            HilosSignalConstants::SECURITY_OAUTH_REDIRECT_RESET => PageConstants::HILOS_SECURITY_OAUTH,
            HilosSignalConstants::SECURITY_OAUTH_PROVIDER_SET => PageConstants::HILOS_SECURITY_OAUTH_PROVIDER,
            HilosSignalConstants::SECURITY_OAUTH_PROVIDER_RESET => PageConstants::HILOS_SECURITY_OAUTH_PROVIDER,
            HilosSignalConstants::SECURITY_SIGN_IN_METHOD_SET => HilosPageConstants::HILOS_SECURITY_SIGN_IN_METHODS,
            HilosSignalConstants::SECURITY_PASSKEY_UNPROVEN_SET => HilosPageConstants::HILOS_SECURITY_SIGN_IN_METHODS,
            HilosSignalConstants::SECURITY_IMPERSONATION_SWITCH_SET => HilosPageConstants::HILOS_SECURITY_IMPERSONATION,
            HilosSignalConstants::SECURITY_IMPERSONATION_SCOPE_SET => HilosPageConstants::HILOS_SECURITY_IMPERSONATION,
            HilosSignalConstants::HILOS_TERMS_REVISION_TEXT => HilosPageConstants::HILOS_TERMS,
        ], Hilos::getPageActionRoutes());
    }

    public function testComputedActionAgentRoutesUseOwningPageSubscriptionAgents(): void
    {
        $this->assertSame([
            ChatSignalConstants::MESSAGE => AgentType::CHAT,
            HilosSignalConstants::HILOS_LINK_OAUTH_START => AgentType::CHAT,
            ChatSignalConstants::BOT_CREATE => AgentType::LIBRARY,
            ChatSignalConstants::BOT_UPDATE => AgentType::LIBRARY,
            ChatSignalConstants::BOT_DELETE => AgentType::LIBRARY,
            HilosSignalConstants::SETTING_ADD => AgentType::HILOS_INDEX,
            HilosSignalConstants::SETTING_UPDATE => AgentType::HILOS_INDEX,
            HilosSignalConstants::SETTING_DELETE => AgentType::HILOS_INDEX,
            HilosSignalConstants::SETTING_RESET => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF => HilosAgentType::HILOS_I18N_LIBRARY,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_START => AgentType::HILOS_GUARDIAN,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => AgentType::HILOS_GUARDIAN,
            HilosSignalConstants::BACKUP_CREATE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_DELETE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_BULK_DELETE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_SET_KEEP => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_RESTORE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_REOPEN => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => AgentType::HILOS_INDEX,
            HilosSignalConstants::LOGS_TAKEOUT_CONFIRM => AgentType::HILOS_LOGS,
            HilosSignalConstants::LOGS_TAKEOUT_UNDO => AgentType::HILOS_LOGS,
            HilosSignalConstants::LOGS_READ_LINES => AgentType::HILOS_LOGS,
            HilosSignalConstants::LOGS_FOLLOW_START => AgentType::HILOS_LOGS,
            HilosSignalConstants::LOGS_FOLLOW_STOP => AgentType::HILOS_LOGS,
            HilosSignalConstants::SETTING_PRESET_APPLY => AgentType::HILOS_LOGS,
            HilosSignalConstants::HILOS_USER_UPDATE => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_USER_MERGE => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_USER_ADMIN_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_USER_BLOCK_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_USER_DELETION_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_IMPERSONATE_START => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_2FA_SETTING_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::LEGAL_ACCEPTANCES_EXPORT => AgentType::HILOS_LEGAL,
            HilosSignalConstants::LEGAL_SETTING_SET => AgentType::HILOS_LEGAL,
            HilosSignalConstants::HILOS_LEGAL_REVISION_TEXT => AgentType::CHAT,
            HilosSignalConstants::HILOS_LEGAL_REVISION_CHANGES => AgentType::CHAT,
            HilosSignalConstants::SECURITY_OAUTH_REDIRECT_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_OAUTH_REDIRECT_RESET => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_OAUTH_PROVIDER_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_OAUTH_PROVIDER_RESET => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_SIGN_IN_METHOD_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_PASSKEY_UNPROVEN_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_IMPERSONATION_SWITCH_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_IMPERSONATION_SCOPE_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_TERMS_REVISION_TEXT => AgentType::HILOS_INDEX,
        ], Hilos::getActionAgentRoutes());
    }

    public function testComputedPageSignalRoutesMatchChatSignalOwnership(): void
    {
        $this->assertSame([
            SignalTypeConstants::AGENT_SIGNAL => [
                ChatSignalConstants::MODERATION_RESULT => PageConstants::MAIN,
                ChatSignalConstants::ATTACHMENTS_PUBLISHED => PageConstants::MAIN,
                HilosSignalConstants::HILOS_SETTING_WRITE_DONE => HilosPageConstants::HILOS_SETTINGS,
                HilosSignalConstants::HILOS_BACKUP_DELETE_DONE => HilosPageConstants::HILOS_BACKUP,
                HilosSignalConstants::HILOS_LOGS_SETTINGS_PRESET_APPLY_DONE
                    => HilosPageConstants::HILOS_LOGS_SETTINGS,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_IMPERSONATE_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE
                    => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
                HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE
                    => HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
                HilosSignalConstants::HILOS_SECOND_FACTOR_SETTING_WRITE_DONE => HilosPageConstants::HILOS_SECURITY_2FA,
                HilosSignalConstants::HILOS_STEP_UP_OPERATIONS_WRITE_DONE => HilosPageConstants::HILOS_SECURITY_STEP_UP,
                HilosSignalConstants::HILOS_LEGAL_SETTING_WRITE_DONE => HilosPageConstants::HILOS_LEGAL_SETTINGS,
                HilosSignalConstants::HILOS_OAUTH_REDIRECT_WRITE_DONE => HilosPageConstants::HILOS_SECURITY_OAUTH,
                HilosSignalConstants::HILOS_SIGN_IN_METHODS_WRITE_DONE => HilosPageConstants::HILOS_SECURITY_SIGN_IN_METHODS,
                HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE => HilosPageConstants::HILOS_SECURITY_IMPERSONATION,
            ],
        ], Hilos::getPageSignalRoutes());
    }

    public function testComputedPageSignalAgentRoutesUseOwningPageSubscriptionAgents(): void
    {
        $this->assertSame([
            SignalTypeConstants::AGENT_SIGNAL => [
                ChatSignalConstants::MODERATION_RESULT => AgentType::CHAT,
                ChatSignalConstants::ATTACHMENTS_PUBLISHED => AgentType::CHAT,
                HilosSignalConstants::HILOS_SETTING_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_BACKUP_DELETE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_LOGS_SETTINGS_PRESET_APPLY_DONE => AgentType::HILOS_LOGS,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_IMPERSONATE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_SECOND_FACTOR_SETTING_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_STEP_UP_OPERATIONS_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_LEGAL_SETTING_WRITE_DONE => AgentType::HILOS_LEGAL,
                HilosSignalConstants::HILOS_OAUTH_REDIRECT_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_SIGN_IN_METHODS_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE => AgentType::HILOS_INDEX,
            ],
        ], Hilos::getPageSignalAgentRoutes());
    }

    public function testComputedAgentSignalRoutesMatchChatAgentOwnership(): void
    {
        $this->assertSame([
            HilosSignalConstants::HILOS_DATA_EXPORT_FORGET_USER => HilosAgentType::HILOS_DATA_EXPORT,
            ChatSignalConstants::BOT_MESSAGE => AgentType::CHAT,
            HilosSignalConstants::HILOS_SESSION_STATE => AgentType::CHAT,
            HilosSignalConstants::HILOS_USER_RENAME => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_ADMIN_WRITE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_PASSWORD_REHASH => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_PASSKEY_USE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_PASSWORD_RESET => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_EMAIL_CHANGE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_RENAME_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_PASSWORD_REHASH_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_EMAIL_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => HilosAgentType::HILOS_USERS_LIBRARY,
            ChatSignalConstants::RENAME_MODERATION_RESULT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_PROVEN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_CANCELED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_SESSION_REBIND => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_MERGE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_SESSION_TOAST_RAISE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_IMPERSONATE_REQUEST => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_CODE_SEND_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_SESSION_CARRYOVER_HANDOVER => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AGENTS_GONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_HELD => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_MISSED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_SETUP_PROVEN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_REVOKE_OTHERS => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_FLOW_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_NOTIFICATION_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_DELIVERY_RETRY => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_PUSH_SUBSCRIPTIONS_GONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_FILE_BIND => HilosAgentType::HILOS_FILES_LIBRARY,
            HilosSignalConstants::HILOS_FILE_REMOVE => HilosAgentType::HILOS_FILES_LIBRARY,
            HilosSignalConstants::HILOS_FILE_PUBLISH => HilosAgentType::HILOS_FILES_LIBRARY,
            HilosSignalConstants::HILOS_IMAGE_RENDERED => HilosAgentType::HILOS_FILES_LIBRARY,
            HilosSignalConstants::HILOS_UPLOAD_PUBLISH => HilosAgentType::HILOS_UPLOADS,
            HilosSignalConstants::HILOS_IMAGE_RENDER => HilosAgentType::HILOS_IMAGES,
            HilosSignalConstants::HILOS_SETTING_WRITE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosSignalConstants::HILOS_SETTING_RESET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosSignalConstants::HILOS_SETTING_DELETE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => HilosAgentType::HILOS_SETTINGS_LIBRARY,
            ChatSignalConstants::BOT_AGENT_START => AgentType::BOT,
            HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION => HilosAgentType::HILOS_LOGS,
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION => HilosAgentType::HILOS_DAEMON,
            HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET => HilosAgentType::HILOS_LEGAL,
            HilosSignalConstants::BACKUP_AGENT_CREATE => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_DELETE => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_SET_KEEP => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_RESTORE => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_REOPEN => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_SESSIONS_CARRIED => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_NOTICES_SENT => AgentType::HILOS_BACKUP,
            HilosSignalConstants::HILOS_OAUTH_PENDING => AgentType::HILOS_OAUTH,
            HilosSignalConstants::HILOS_MAIL_DELIVER => AgentType::HILOS_MAIL,
            HilosSignalConstants::HILOS_MAIL_SEND => AgentType::HILOS_MAIL,
            HilosSignalConstants::HILOS_SMS_DELIVER => AgentType::HILOS_SMS,
            HilosSignalConstants::HILOS_SMS_SEND => AgentType::HILOS_SMS,
            HilosSignalConstants::HILOS_PUSH_DELIVER => AgentType::HILOS_PUSH,
            HilosSignalConstants::LOGS_AGENT_READ_LINES => HilosAgentType::HILOS_LOG_STORE,
            HilosSignalConstants::LOGS_AGENT_FOLLOW_START => HilosAgentType::HILOS_LOG_STORE,
            HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP => HilosAgentType::HILOS_LOG_STORE,
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM => HilosAgentType::HILOS_LOG_STORE,
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO => HilosAgentType::HILOS_LOG_STORE,
            HilosSignalConstants::ANALYTICS_JOURNAL_APPEND => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosSignalConstants::ANALYTICS_JOURNAL_READ => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosSignalConstants::ANALYTICS_JOURNAL_LOADED => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosSignalConstants::LOGS_NODE_INDEX_REPORT => HilosAgentType::HILOS_LOG_AGGREGATOR,
            HilosSignalConstants::LOGS_INDEX_WATCH => HilosAgentType::HILOS_LOG_AGGREGATOR,
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER => HilosAgentType::HILOS_DAEMON_NODE,
            HilosSignalConstants::DAEMON_MASTER_CRON => HilosAgentType::HILOS_DAEMON_NODE,
            HilosSignalConstants::DAEMON_MASTER_STANDING => HilosAgentType::HILOS_DAEMON_NODE,
            HilosSignalConstants::DAEMON_MASTER_HTTP => HilosAgentType::HILOS_DAEMON_NODE,
            HilosSignalConstants::DAEMON_AGENT_CRON => HilosAgentType::HILOS_DAEMON_NODE,
            HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT => HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosSignalConstants::DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => HilosAgentType::HILOS_ANALYTICS_WRITER,
            HilosSignalConstants::ANALYTICS_JOURNAL_READY => HilosAgentType::HILOS_ANALYTICS_WRITER,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => AgentType::HILOS_AUTH_THROTTLE,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => AgentType::HILOS_AUTH_THROTTLE,
            HilosSignalConstants::HILOS_AUTH_CODE_SEND => AgentType::HILOS_AUTH_CODE,
        ], Hilos::getAgentSignalRoutes());
    }

    public function testComputedCommandRoutesMatchChatAgentOwnership(): void
    {
        $this->assertSame([
            CliCommands::LEGAL_TEST_HOLD => HilosAgentType::HILOS_USERS_LIBRARY,
            CliCommands::VERIFICATION_TEST_SWEEP => HilosAgentType::HILOS_USERS_LIBRARY,
            CliCommands::VERIFICATION_TEST_END_PAUSE => HilosAgentType::HILOS_USERS_LIBRARY,
            CliCommands::SECOND_FACTOR_UNLOCK => HilosAgentType::HILOS_USERS_LIBRARY,
            CliCommands::ADMIN_CREATE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::ADMIN_GRANT => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::ADMIN_REVOKE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::IMPERSONATE_START => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::IMPERSONATE_STOP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::ACCOUNT_MERGE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::ACCOUNT_TEST_FORCE_PURGE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::NOTIFICATION_TEST_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            CliCommands::I18N_TEST_LANGUAGE_ON => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::COMMAND_TEST_ECHO => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_ENTER => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_LEAVE => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_OPEN => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_PASS => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_CLOSE => AgentType::HILOS_INDEX,
            CliCommands::MAINTENANCE_ENABLE => AgentType::HILOS_INDEX,
            CliCommands::MAINTENANCE_DISABLE => AgentType::HILOS_INDEX,
            CliCommands::MAINTENANCE_PASS => AgentType::HILOS_INDEX,
            CliCommands::BACKUP_TEST_AGE => AgentType::HILOS_BACKUP,
            BackupConstants::PRUNE_COMMAND => AgentType::HILOS_BACKUP,
            BackupConstants::SHIP_COMMAND => AgentType::HILOS_BACKUP,
            BackupConstants::RUN_SCHEDULE_COMMAND => AgentType::HILOS_BACKUP,
            BackupConstants::RESTORE_REQUEST_COMMAND => AgentType::HILOS_BACKUP,
            BackupConstants::RESTORE_STATUS_COMMAND => AgentType::HILOS_BACKUP,
            CliCommands::PROTECTED_MODE_PASS => AgentType::HILOS_BACKUP,
            CliCommands::PROTECTED_MODE_OPEN => AgentType::HILOS_BACKUP,
            CliCommands::PROTECTED_MODE_CLOSE => AgentType::HILOS_BACKUP,
            CliCommands::LOG_TEST_APPEND => HilosAgentType::HILOS_LOG_STORE,
            CliCommands::THROTTLE_TEST_RESET => AgentType::HILOS_AUTH_THROTTLE,
        ], Hilos::getCommandAgentRoutes());
        $this->assertSame([], Hilos::getCommandDtoRoutes());
    }

    /**
     * The HTTP addresses agents answer, beside the commands: the files library declares its
     * download address on the abstract class, so it is here because chat declares FILES, and a
     * second address an agent starts to answer has to land here too (HIL-138).
     */
    public function testComputedHttpRoutesMatchChatAgentOwnership(): void
    {
        $this->assertSame([
            HttpConstants::METHOD_GET => [
                DataExportHttp::DOWNLOAD_PATH => HilosAgentType::HILOS_DATA_EXPORT,
                HilosFiles::DOWNLOAD_PATH => HilosAgentType::HILOS_FILES_LIBRARY,
                LegalAcceptancesExportHttp::DOWNLOAD_PATH => HilosAgentType::HILOS_LEGAL,
            ],
        ], Hilos::getHttpAgentRoutes());
    }

    public function testComputedAgentSignalIndexFieldsMatchBotAgentDeclaration(): void
    {
        $this->assertSame([
            HilosSignalConstants::HILOS_USER_RENAME => 'userId',
            HilosSignalConstants::HILOS_USER_ADMIN_WRITE => 'userId',
            HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => 'userId',
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE => 'userId',
            HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE => 'userId',
            HilosSignalConstants::HILOS_USER_PASSWORD_REHASH => 'userId',
            HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY => 'userId',
            HilosSignalConstants::HILOS_USER_PASSKEY_USE => 'userId',
            HilosSignalConstants::HILOS_USER_PASSWORD_RESET => 'userId',
            HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE => 'userId',
            HilosSignalConstants::HILOS_USER_EMAIL_CHANGE => 'userId',
            HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK => 'userId',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE => 'userId',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM => 'userId',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE => 'userId',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL => 'userId',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE => 'userId',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE => 'userId',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND => 'userId',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK => 'userId',
            ChatSignalConstants::BOT_AGENT_START => 'botId',
            HilosSignalConstants::HILOS_MAIL_DELIVER => NotificationDeliverSignalData::shardKey,
            HilosSignalConstants::HILOS_MAIL_SEND => MailSendSignalData::shardKey,
            HilosSignalConstants::HILOS_SMS_DELIVER => NotificationDeliverSignalData::shardKey,
            HilosSignalConstants::HILOS_SMS_SEND => SmsSendSignalData::shardKey,
            HilosSignalConstants::HILOS_PUSH_DELIVER => NotificationDeliverSignalData::shardKey,
        ], Hilos::getAgentSignalIndexFields());
    }

    public function testComputedAgentSignalNodeFieldsMatchLogStoreDeclaration(): void
    {
        $this->assertSame([
            HilosSignalConstants::LOGS_AGENT_READ_LINES => LogsReadLinesActionDTO::nodeId,
            HilosSignalConstants::LOGS_AGENT_FOLLOW_START => LogsFollowStartActionDTO::nodeId,
            HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP => LogsFollowStopActionDTO::nodeId,
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM => LogsTakeoutConfirmActionDTO::nodeId,
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO => LogsTakeoutUndoActionDTO::nodeId,
            HilosSignalConstants::ANALYTICS_JOURNAL_READ => AnalyticsJournalReadSignalData::nodeId,
            HilosSignalConstants::ANALYTICS_JOURNAL_LOADED => AnalyticsJournalLoadedSignalData::nodeId,
        ], Hilos::getAgentSignalNodeFields());
    }

    public function testAgentSignalDtoRoutesCoverDeclaredAgentSignals(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            $this->assertNotNull($agentClass);
            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                    $declaredRoutes[$key] = $value;
                    continue;
                }

                if (!is_string($key) || $key === '' || !is_array($value)) {
                    continue;
                }

                $dtoClass = $value[AgentSignalConfigKey::DTO] ?? null;
                if (is_string($dtoClass) && $dtoClass !== '') {
                    $declaredRoutes[$key] = $dtoClass;
                }
            }
        }

        $this->assertSame([
            HilosSignalConstants::HILOS_DATA_EXPORT_FORGET_USER => DataExportForgetUserSignalData::class,
            ChatSignalConstants::BOT_MESSAGE => BotMessageSignalData::class,
            HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class,
            HilosSignalConstants::HILOS_USER_RENAME => UserRenameSignalData::class,
            HilosSignalConstants::HILOS_USER_ADMIN_WRITE => UserAdminWriteSignalData::class,
            HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => UserAdminCommandSignalData::class,
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE => UserBlockWriteSignalData::class,
            HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE => UserThemePickWriteSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSWORD_REHASH => UserPasswordRehashSignalData::class,
            HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY => UserAddressVerifySignalData::class,
            HilosSignalConstants::HILOS_USER_PASSKEY_USE => UserPasskeyUseSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSWORD_RESET => UserPasswordResetSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE => UserPasswordChangeSignalData::class,
            HilosSignalConstants::HILOS_USER_EMAIL_CHANGE => UserEmailChangeSignalData::class,
            HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK => UserIdentityUnlinkSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE => UserSecondFactorProveSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM => UserSecondFactorEnrollConfirmSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE => UserSecondFactorRemoveSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL => UserSecondFactorResetCancelSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE => UserSecondFactorWaitWriteSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE => UserSecondFactorResetDueSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND => UserSecondFactorResetRemindSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK => UserSecondFactorUnlockSignalData::class,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
            HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => OAuthLoginReadySignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET => AccountDeletionSetSignalData::class,
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME => AdminRenameSignalData::class,
            HilosSignalConstants::HILOS_USER_RENAME_DONE => UserRenameDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSWORD_REHASH_DONE => UserPasswordRehashDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE => UserAddressVerifyDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE => UserPasskeyUseDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE => UserPasswordResetDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE => UserPasswordChangeDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_EMAIL_CHANGE_DONE => UserEmailChangeDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE => UserIdentityUnlinkDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE => UserSecondFactorProveDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE => UserSecondFactorEnrollConfirmDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE => UserSecondFactorRemoveDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE => UserSecondFactorResetCancelDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE => UserSecondFactorWaitWriteDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE => UserSecondFactorResetDueDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE => UserSecondFactorResetRemindDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK_DONE => UserSecondFactorUnlockDoneSignalData::class,
            HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT => ProfilePhotoVerdictSignalData::class,
            HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => FilesPublishedSignalData::class,
            ChatSignalConstants::RENAME_MODERATION_RESULT => RenameModerationResultSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT => AuthSessionGrantSignalData::class,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_PROVEN => AuthRegistrationProvenSignalData::class,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED => AuthRegistrationLandedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED => AuthRecoveryGrantedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED => AuthPasswordChangedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_CANCELED => AuthRegistrationCanceledSignalData::class,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED => AuthRegistrationWaitMovedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED => AuthRecoveryWaitMovedSignalData::class,
            HilosSignalConstants::HILOS_SESSION_REBIND => SessionRebindSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_MERGE => AccountMergeSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET => AccountAdminSetSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET => AccountBlockSetSignalData::class,
            HilosSignalConstants::HILOS_SESSION_TOAST_RAISE => RaiseSessionToastSignalData::class,
            HilosSignalConstants::HILOS_IMPERSONATE_REQUEST => ImpersonateRequestSignalData::class,
            HilosSignalConstants::HILOS_CODE_SEND_STEP => CodeSendStepSignalData::class,
            HilosSignalConstants::HILOS_SESSION_CARRYOVER_HANDOVER => DeferredSessionCarryoverHandoverSignalData::class,
            HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED => OAuthTripOpenedSignalData::class,
            HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED => OAuthTripEndedSignalData::class,
            HilosSignalConstants::HILOS_AGENTS_GONE => AgentsGoneSignalData::class,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_HELD => AuthRegistrationWaitHeldSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_MISSED => AuthSecondFactorMissedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_SETUP_PROVEN => AuthSecondFactorSetupProvenSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF => AuthSecondFactorOffSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY => AuthSecondFactorTrustDaysApplySignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_REVOKE_OTHERS => AuthSecondFactorTrustRevokeOthersSignalData::class,
            HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END => AuthOtherSessionsEndSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_CANCEL => AuthSecondFactorCancelSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED => AccountBlockChangedSignalData::class,
            HilosSignalConstants::HILOS_PROFILE_FLOW_STEP => ProfileFlowStepSignalData::class,
            HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE => UserSessionsRestateSignalData::class,
            HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE => UserAdminWriteDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE => UserAdminCommandDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE => UserBlockWriteDoneSignalData::class,
            HilosSignalConstants::HILOS_NOTIFICATION_EMIT => NotificationEmitSignalData::class,
            HilosSignalConstants::HILOS_DELIVERY_RETRY => DeliveryRetrySignalData::class,
            HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => DeferredNotificationHandoverSignalData::class,
            HilosSignalConstants::HILOS_PUSH_SUBSCRIPTIONS_GONE => PushSubscriptionsGoneSignalData::class,
            HilosSignalConstants::HILOS_FILE_BIND => FileBindSignalData::class,
            HilosSignalConstants::HILOS_FILE_REMOVE => FileRemoveSignalData::class,
            HilosSignalConstants::HILOS_FILE_PUBLISH => FilePublishSignalData::class,
            HilosSignalConstants::HILOS_IMAGE_RENDERED => ImageRenderedSignalData::class,
            HilosSignalConstants::HILOS_UPLOAD_PUBLISH => UploadPublishSignalData::class,
            HilosSignalConstants::HILOS_IMAGE_RENDER => ImageRenderSignalData::class,
            HilosSignalConstants::HILOS_SETTING_WRITE => SettingWriteSignalData::class,
            HilosSignalConstants::HILOS_SETTING_RESET => SettingResetSignalData::class,
            HilosSignalConstants::HILOS_SETTING_DELETE => SettingDeleteSignalData::class,
            HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => SettingPresetApplySignalData::class,
            ChatSignalConstants::BOT_AGENT_START => BotAgentSignalData::class,
            HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION => ClusterLogIndexPortionSignalData::class,
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
            HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET => LegalAcceptancesExportForgetSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_CREATE => BackupCreateSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_DELETE => BackupDeleteSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_SET_KEEP => BackupSetKeepSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_RESTORE => BackupRestoreSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_REOPEN => BackupReopenSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_SESSIONS_CARRIED => DeferredSessionsCarriedSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_NOTICES_SENT => DeferredNoticesSentSignalData::class,
            HilosSignalConstants::HILOS_OAUTH_PENDING => OAuthPendingLoginSignalData::class,
            HilosSignalConstants::HILOS_MAIL_DELIVER => NotificationDeliverSignalData::class,
            HilosSignalConstants::HILOS_MAIL_SEND => MailSendSignalData::class,
            HilosSignalConstants::HILOS_SMS_DELIVER => NotificationDeliverSignalData::class,
            HilosSignalConstants::HILOS_SMS_SEND => SmsSendSignalData::class,
            HilosSignalConstants::HILOS_PUSH_DELIVER => NotificationDeliverSignalData::class,
            HilosSignalConstants::LOGS_AGENT_READ_LINES => LogsReadLinesSignalData::class,
            HilosSignalConstants::LOGS_AGENT_FOLLOW_START => LogsFollowStartSignalData::class,
            HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP => LogsFollowStopSignalData::class,
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM => LogsTakeoutConfirmSignalData::class,
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO => LogsTakeoutUndoSignalData::class,
            HilosSignalConstants::ANALYTICS_JOURNAL_APPEND => AnalyticsJournalAppendSignalData::class,
            HilosSignalConstants::ANALYTICS_JOURNAL_READ => AnalyticsJournalReadSignalData::class,
            HilosSignalConstants::ANALYTICS_JOURNAL_LOADED => AnalyticsJournalLoadedSignalData::class,
            HilosSignalConstants::LOGS_NODE_INDEX_REPORT => NodeLogIndexSignalData::class,
            HilosSignalConstants::LOGS_INDEX_WATCH => LogsIndexWatchSignalData::class,
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER => DaemonMasterProcessRosterSignalData::class,
            HilosSignalConstants::DAEMON_MASTER_CRON => DaemonMasterCronSignalData::class,
            HilosSignalConstants::DAEMON_MASTER_STANDING => DaemonMasterStandingSignalData::class,
            HilosSignalConstants::DAEMON_MASTER_HTTP => DaemonMasterHttpSignalData::class,
            HilosSignalConstants::DAEMON_AGENT_CRON => DaemonAgentCronSignalData::class,
            HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT => DaemonNodePictureSignalData::class,
            HilosSignalConstants::DAEMON_PICTURE_WATCH => DaemonPictureWatchSignalData::class,
            HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => AnalyticsJournalPortionSignalData::class,
            HilosSignalConstants::ANALYTICS_JOURNAL_READY => AnalyticsJournalReadySignalData::class,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => ThrottleCheckSignalData::class,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => ThrottleSuccessSignalData::class,
            HilosSignalConstants::HILOS_AUTH_CODE_SEND => AuthCodeSendSignalData::class,
        ], $declaredRoutes);
        $this->assertSame($declaredRoutes, Hilos::getAgentSignalDtoRoutes());
    }

    public function testComputedAgentActionRoutesMatchChatAgentOwnership(): void
    {
        $this->assertSame([
            HilosSignalConstants::HILOS_DATA_EXPORT_ORDER => HilosAgentType::HILOS_DATA_EXPORT,
            HilosSignalConstants::HILOS_DETECT_IDENTIFIER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_CONSENT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_ACCEPT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LOGIN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REGISTER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_REGISTER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CANCEL_REGISTRATION => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_PHONE_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_CALLBACK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_CREATE_ACCOUNT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CANCEL_SECOND_FACTOR => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_FINISH => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_STEP_UP_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SET_PASSWORD => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_UNLINK_IDENTITY => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_SMS_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_PHOTO_SET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_PHOTO_REMOVE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL => HilosAgentType::HILOS_USERS_LIBRARY,
            ChatSignalConstants::RENAME => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LOGOUT => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_SESSION_END => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_SESSIONS_END_OTHERS => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_BROWSER_ERASE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_DISMISS_SESSION_ACK => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_DISMISS_ACCOUNT_BLOCKED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_IMPERSONATE_STOP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_TOAST_DISMISS => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_TOAST_EXPIRED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_TOAST_READING => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_RESUME => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_FLOW_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            NotificationAction::MARK_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationAction::MARK_ALL_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationPreferenceAction::CHANNEL_SET => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::SUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::UNSUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::REMOVE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_UPLOAD_INIT => HilosAgentType::HILOS_UPLOADS,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL => HilosAgentType::HILOS_UPLOADS,
        ], Hilos::getAgentActionRoutes());
    }

    public function testAgentActionDtoRoutesCoverDeclaredAgentActions(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            $this->assertNotNull($agentClass);
            foreach ($agentClass::AGENT_ACTIONS as $action => $dtoClass) {
                $declaredRoutes[$action] = $dtoClass;
            }
        }

        $this->assertSame([
            HilosSignalConstants::HILOS_DATA_EXPORT_ORDER => DataExportOrderActionDTO::class,
            HilosSignalConstants::HILOS_DETECT_IDENTIFIER => DetectIdentifierActionDTO::class,
            HilosSignalConstants::HILOS_LEGAL_CONSENT => LegalConsentActionDTO::class,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT => LegalReconsentActionDTO::class,
            HilosSignalConstants::HILOS_LEGAL_ACCEPT => LegalAcceptActionDTO::class,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW => LegalReconsentPreviewActionDTO::class,
            HilosSignalConstants::HILOS_LOGIN => LoginActionDTO::class,
            HilosSignalConstants::HILOS_REGISTER => RegisterActionDTO::class,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET => RequestPasswordResetActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET => ConfirmPasswordResetActionDTO::class,
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => CompletePasswordResetActionDTO::class,
            HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM => RequestRegisterConfirmActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_REGISTER => ConfirmRegisterActionDTO::class,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION => CompleteRegistrationActionDTO::class,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS =>
                CompleteRegistrationPasswordlessActionDTO::class,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY => CompleteRegistrationPasskeyActionDTO::class,
            HilosSignalConstants::HILOS_CANCEL_REGISTRATION => CancelRegistrationActionDTO::class,
            HilosSignalConstants::HILOS_REQUEST_PHONE_CODE => RequestPhoneCodeActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE => ConfirmPhoneCodeActionDTO::class,
            HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK => RequestMagicLinkActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK => ConfirmMagicLinkActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE => ConfirmMagicLinkCodeActionDTO::class,
            HilosSignalConstants::HILOS_OAUTH_START => OAuthStartActionDTO::class,
            HilosSignalConstants::HILOS_OAUTH_CALLBACK => OAuthCallbackActionDTO::class,
            HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH => LinkOAuthAfterReauthActionDTO::class,
            HilosSignalConstants::HILOS_OAUTH_CREATE_ACCOUNT => OAuthCreateAccountActionDTO::class,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS => PasskeyRegisterOptionsActionDTO::class,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM => PasskeyRegisterConfirmActionDTO::class,
            HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS => RegistrationPasskeyOptionsActionDTO::class,
            HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS => PasskeyDiscoverableLoginOptionsActionDTO::class,
            HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM => PasskeyLoginConfirmActionDTO::class,
            HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR => ConfirmSecondFactorActionDTO::class,
            HilosSignalConstants::HILOS_CANCEL_SECOND_FACTOR => CancelSecondFactorActionDTO::class,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START => SecondFactorSetupStartActionDTO::class,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM => SecondFactorSetupConfirmActionDTO::class,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_FINISH => SecondFactorSetupFinishActionDTO::class,
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_REQUEST => SecondFactorResetRequestActionDTO::class,
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK => SecondFactorResetCancelLinkActionDTO::class,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START => ProfileSecondFactorEnrollStartActionDTO::class,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM => ProfileSecondFactorEnrollConfirmActionDTO::class,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE => ProfileSecondFactorRemoveActionDTO::class,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW => ProfileSecondFactorCodesShowActionDTO::class,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW => ProfileSecondFactorCodesRenewActionDTO::class,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET => ProfileSecondFactorResetWaitSetActionDTO::class,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST => ProfileSecondFactorResetRequestActionDTO::class,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL => ProfileSecondFactorResetCancelActionDTO::class,
            HilosSignalConstants::HILOS_STEP_UP_START => StepUpStartActionDTO::class,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM => StepUpConfirmActionDTO::class,
            HilosSignalConstants::PROFILE_SET_PASSWORD => ProfileSetPasswordActionDTO::class,
            HilosSignalConstants::PROFILE_UNLINK_IDENTITY => ProfileUnlinkIdentityActionDTO::class,
            HilosSignalConstants::PROFILE_ADD_SMS_REQUEST => ProfileAddSmsRequestActionDTO::class,
            HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM => ProfileAddSmsConfirmActionDTO::class,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST => ProfileAddPasswordRequestActionDTO::class,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM => ProfileAddPasswordConfirmActionDTO::class,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST => ProfileEmailChangeCurrentRequestActionDTO::class,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM => ProfileEmailChangeCurrentConfirmActionDTO::class,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST => ProfileEmailChangeNewRequestActionDTO::class,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM => ProfileEmailChangeNewConfirmActionDTO::class,
            HilosSignalConstants::PROFILE_PHOTO_SET => ProfilePhotoSetActionDTO::class,
            HilosSignalConstants::PROFILE_PHOTO_REMOVE => ProfilePhotoRemoveActionDTO::class,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN => ProfileChangePasswordOpenActionDTO::class,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST => ProfileChangePasswordCodeRequestActionDTO::class,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM => ProfileChangePasswordCodeConfirmActionDTO::class,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD => ProfileChangePasswordActionDTO::class,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN => AccountDeletionOpenActionDTO::class,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE => AccountDeletionCodeActionDTO::class,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_START => AccountDeletionStartActionDTO::class,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL => AccountDeletionCancelActionDTO::class,
            ChatSignalConstants::RENAME => RenameActionDTO::class,
            HilosSignalConstants::HILOS_LOGOUT => LogoutActionDTO::class,
            HilosSignalConstants::HILOS_SESSION_END => SessionEndActionDTO::class,
            HilosSignalConstants::HILOS_SESSIONS_END_OTHERS => SessionsEndOthersActionDTO::class,
            HilosSignalConstants::HILOS_BROWSER_ERASE => BrowserEraseActionDTO::class,
            HilosSignalConstants::HILOS_DISMISS_SESSION_ACK => DismissSessionAckActionDTO::class,
            HilosSignalConstants::HILOS_DISMISS_ACCOUNT_BLOCKED => DismissAccountBlockedActionDTO::class,
            HilosSignalConstants::HILOS_IMPERSONATE_STOP => ImpersonateStopActionDTO::class,
            HilosSignalConstants::HILOS_TOAST_DISMISS => DismissSessionToastActionDTO::class,
            HilosSignalConstants::HILOS_TOAST_EXPIRED => SessionToastExpiredActionDTO::class,
            HilosSignalConstants::HILOS_TOAST_READING => SessionToastReadingActionDTO::class,
            HilosSignalConstants::HILOS_OAUTH_RESUME => OAuthResumeActionDTO::class,
            HilosSignalConstants::HILOS_PROFILE_FLOW_CANCEL => ProfileFlowCancelActionDTO::class,
            NotificationAction::MARK_READ => NotificationMarkReadPayloadDTO::class,
            NotificationAction::MARK_ALL_READ => NotificationMarkAllReadPayloadDTO::class,
            NotificationPreferenceAction::CHANNEL_SET => NotificationChannelPreferenceActionDTO::class,
            PushSubscriptionAction::SUBSCRIBE => PushSubscribeActionDTO::class,
            PushSubscriptionAction::UNSUBSCRIBE => PushUnsubscribeActionDTO::class,
            PushSubscriptionAction::REMOVE => PushRemoveActionDTO::class,
            HilosSignalConstants::HILOS_UPLOAD_INIT => UploadInitActionDTO::class,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL => UploadCancelActionDTO::class,
        ], $declaredRoutes);
        $this->assertSame($declaredRoutes, Hilos::getAgentActionDtoRoutes());
    }

    public function testPageActionRoutesCoverDeclaredPageActions(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::PAGES as $page => $pageClass) {
            foreach ($pageClass::ACTIONS as $action => $_dtoClass) {
                $declaredRoutes[$action] = $page;
            }
        }

        $this->assertSame($declaredRoutes, Hilos::getPageActionRoutes());
    }

    public function testActionDtoRoutesCoverDeclaredPageActions(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::PAGES as $page => $pageClass) {
            foreach ($pageClass::ACTIONS as $action => $dtoClass) {
                $declaredRoutes[$action] = $dtoClass;
            }
        }

        $this->assertSame($declaredRoutes, Hilos::getActionDtoRoutes());
    }

    public function testPageSignalRoutesCoverDeclaredPageSignals(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::PAGES as $page => $pageClass) {
            foreach ($pageClass::SIGNALS as $signalType => $signalNames) {
                if ($signalNames === []) {
                    $declaredRoutes[$signalType] = $page;
                    continue;
                }

                foreach ($signalNames as $signalKey => $signalValue) {
                    $signalName = is_int($signalKey) ? $signalValue : $signalKey;
                    if (!is_string($signalName) || $signalName === '') {
                        continue;
                    }

                    $declaredRoutes[$signalType][$signalName] = $page;
                }
            }
        }

        $this->assertSame($declaredRoutes, Hilos::getPageSignalRoutes());
    }

    public function testAgentSignalRoutesCoverDeclaredAgentSignals(): void
    {
        $declaredRoutes = [];
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            $this->assertNotNull($agentClass);
            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (is_int($key) && is_string($value) && $value !== '') {
                    $declaredRoutes[$value] = $agentType;
                } elseif (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                    $declaredRoutes[$key] = $agentType;
                } elseif (is_string($key) && $key !== '' && is_array($value)) {
                    $declaredRoutes[$key] = $agentType;
                }
            }
        }

        $this->assertSame($declaredRoutes, Hilos::getAgentSignalRoutes());
    }

    public function testActionRouteConfigUsesComputedPageActionRoutes(): void
    {
        $actionRoutes = new ActionRouteConfig(Hilos::getPageActionRoutes());

        foreach (Hilos::getPageActionRoutes() as $action => $page) {
            $this->assertSame($page, $actionRoutes->getPageForAction($action));
        }
    }

    public function testBrowserTableRegistryKeysMatchTableClassConstants(): void
    {
        foreach ($this->mergedBrowserSources() as $table => $tableClass) {
            $sourceKey = match (true) {
                defined("{$tableClass}::LIST") => $tableClass::LIST,
                defined("{$tableClass}::DATA") => $tableClass::DATA,
                default => $tableClass::TABLE,
            };
            $this->assertSame($table, $sourceKey);
        }
    }

    public function testPageTablesUseRegisteredTableKeys(): void
    {
        $browserSources = $this->mergedBrowserSources();

        foreach ($this->mergedPageSources() as $page => $tables) {
            $this->assertArrayHasKey($page, Hilos::PAGES);

            foreach ($tables as $table => $config) {
                $this->assertTrue(
                    isset(Hilos::TABLES[$table]) || isset($browserSources[$table]),
                    "{$page} references unknown source {$table}",
                );
                $this->assertIsArray($config);
            }
        }
    }

    public function testPageBrowserConfigsDoNotDeclareTableBindings(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertArrayNotHasKey(
                BrowserConfigKey::TABLES,
                $pageClass::BROWSER,
                "{$page} must declare page-table bindings in Hilos::PAGE_TABLES",
            );
        }
    }

    public function testChatBrowserContextDoesNotUseLegacyManualTopologyLists(): void
    {
        $reflection = new ReflectionClass(ChatBrowserContext::class);

        $this->assertFalse($reflection->getReflectionConstant('PAGES'));
        $this->assertFalse($reflection->getReflectionConstant('TABLES'));
    }

    public function testChatBrowserContextResolvesPageMetadataFromTopology(): void
    {
        $context = new ChatBrowserContext();
        Hilos::initBrowser($context);
        $resolvePageConfig = Closure::bind(
            static fn(ChatBrowserContext $context, string $page): ?BrowserPageConfig => $context->resolveBrowserPageConfig($page),
            null,
            ChatBrowserContext::class,
        );

        foreach (Hilos::PAGES as $page => $pageClass) {
            $browserConfig = $pageClass::BROWSER;
            $config = $resolvePageConfig($context, $page);

            $this->assertNotNull($config);
            $this->assertSame($this->expectedPageParams($browserConfig), $config->paramConfigs());
            $this->assertSame($this->expectedPageGuards($browserConfig), $config->guardConfigs());
        }

        $this->assertNull($resolvePageConfig($context, 'missing_page'));
    }

    public function testChatBrowserContextResolvesPageTableBindingsFromTopology(): void
    {
        $context = new ChatBrowserContext();
        Hilos::initBrowser($context);
        $resolvePageTables = Closure::bind(
            static fn(ChatBrowserContext $context, string $page): BrowserPageBindings => $context->resolveBrowserPageBindings($page),
            null,
            ChatBrowserContext::class,
        );

        foreach ($this->mergedPageSources() as $page => $tableConfigs) {
            $bindings = iterator_to_array($resolvePageTables($context, $page), false);

            $this->assertSame(array_keys($tableConfigs), array_map(static fn($binding): string => $binding->browserKey, $bindings));
            foreach ($bindings as $binding) {
                $browserConfig = $tableConfigs[$binding->browserKey] ?? [];
                $this->assertSame($this->expectedBindingParamRefs($browserConfig), $binding->paramRefs());
            }
        }

        $this->assertSame([], iterator_to_array($resolvePageTables($context, 'missing_page'), false));
    }

    public function testChatBrowserContextResolvesBrowserOnlyTablesFromTopology(): void
    {
        $context = new ChatBrowserContext();
        Hilos::initBrowser($context);
        $resolveTableConfig = Closure::bind(
            static fn(ChatBrowserContext $context, string $tableKey): ?BrowserSourceConfig => $context->resolveBrowserOnlyConfig($tableKey),
            null,
            ChatBrowserContext::class,
        );

        foreach ($this->mergedBrowserSources() as $table => $tableClass) {
            $config = $resolveTableConfig($context, $table);

            $this->assertNotNull($config);
            $this->assertSame($this->expectedTableRows($tableClass::BROWSER), $config->rowConfigs());
        }

        $this->assertNull($resolveTableConfig($context, ChatTableContext::settings));
        $this->assertNull($resolveTableConfig($context, 'missing_table'));
    }

    public function testHilosPageFactoryCreatesRegisteredPagesFromTopology(): void
    {
        $factory = new HilosPageFactory($this->pageAgent(), Hilos::class);

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertInstanceOf($pageClass, $factory->getPage($page));
        }
    }

    public function testChatTableContextRegistersTablesFromTopology(): void
    {
        $context = new ChatTableContext();
        $context->configure();

        foreach (Hilos::TABLES as $table => $tableClass) {
            $this->assertInstanceOf($tableClass, $context->get($table));
        }
    }

    public function testProjectTopologyPassesStartupValidation(): void
    {
        // The first check init() runs, and the one every project boots under. It judges
        // this project's real registry, unlike the framework's TopologyValidatorTest, which
        // only runs it against invented fixture facades.
        Hilos::validateTopology();

        $this->addToAssertionCount(1);
    }

    /**
     * The length of the shared-ownership debt, so no receipt can be written in silence.
     *
     * A ceiling and not the exact rows: an addition paints this red, a removal passes quietly,
     * because nothing should stand in the way of a debt getting smaller. Asserting the exact
     * contents instead would paint this test red on every PARTING - the one move the list
     * exists to bring about. Five database collections carry it today, and no runtime collection
     * is shared here at all.
     */
    public function testSharedOwnershipDebtDoesNotGrow(): void
    {
        $this->assertLessThanOrEqual(5, count(Hilos::SHARED_DB_OWNERS));
        $this->assertSame([], Hilos::SHARED_RT_OWNERS);
    }

    public function testDeclaredFeaturesAreFullyActivated(): void
    {
        // The startup activation check this project boots under: every declared feature has
        // its pages, agents, tables, bindings and catalogs, and nothing framework-owned is
        // registered without the declaration that switches it on.
        Hilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeaturesHaveWhatStartupCannotCheck(): void
    {
        // The other half of the activation check, the half no starting process can make: the SQL
        // tables a declared feature reads live in migrations applied as a separate step, and the
        // presence source behind the users list is a runtime collection, and its block source a database one - none is a constant.
        // The backup children left this list with HIL-729: the framework registers them itself.
        Hilos::validateDeferredFeatureRequirements(
            __DIR__ . '/../../backend/Database/Migration/Schema',
            ChatCliManager::class,
            ChatRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @throws HilosException When the context refuses to configure, or the guard refuses a chain under a framework key
     */
    public function testDatabaseMountsAreWhole(): void
    {
        // The question the daemon asks first on its start, over this project's context and
        // without a database: configure() reads nothing. The chain judged is this demo's rename
        // journal, which carries the event of the feed (HIL-1196).
        $previous = Hilos::$db;
        try {
            Hilos::$db = new ChatDbContext();
            Hilos::$db->configure();
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
            MountedCollectionKeyGuard::assertMountedKeysAgree();
        } finally {
            Hilos::$db = $previous;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Creates a minimal page agent for page factory tests.
     */
    private function pageAgent(): PageAgentInterface
    {
        return new class implements PageAgentInterface {
            /**
             * Return the fixture agent id.
             *
             * @return string Agent id
             */
            public function getId(): string
            {
                return 'test-page-agent';
            }

            /**
             * Return the fixture signal source for page helpers.
             *
             * @return SignalSourceInterface Signal source
             */
            public function getAgentSignalSource(): SignalSourceInterface
            {
                return new SignalSource(SignalSource::AGENT, 'test-page-agent');
            }
        };
    }

    /**
     * Extracts expected route param declarations from a page config.
     *
     * @param array<string, mixed> $browserConfig Page BROWSER config
     * @return array<string, mixed> Route param declarations
     */
    private function expectedPageParams(array $browserConfig): array
    {
        $params = $browserConfig[BrowserConfigKey::PARAMS] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Extracts expected guard declarations from a page config.
     *
     * @param array<string, mixed> $browserConfig Page BROWSER config
     * @return list<array<string, mixed>> Guard declarations
     */
    private function expectedPageGuards(array $browserConfig): array
    {
        $guards = $browserConfig[BrowserConfigKey::GUARDS] ?? [];

        return is_array($guards)
            ? array_values(array_filter($guards, static fn(mixed $guard): bool => is_array($guard)))
            : [];
    }

    /**
     * Extracts expected binding param references from a PAGE_TABLES entry.
     *
     * @param mixed $browserConfig Page table binding config
     * @return array<string, mixed> Table param reference declarations
     */
    private function expectedBindingParamRefs(mixed $browserConfig): array
    {
        if (!is_array($browserConfig)) {
            return [];
        }

        $params = $browserConfig[BrowserParamKey::PARAMS] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Extracts expected row configs from a table BROWSER config.
     *
     * @param array<string, mixed> $browserConfig Table BROWSER config
     * @return list<array<string, mixed>> Row source configs
     */
    private function expectedTableRows(array $browserConfig): array
    {
        $rows = $browserConfig[BrowserListConfigKey::ITEMS] ?? $browserConfig[BrowserTableConfigKey::ROWS] ?? [];

        return is_array($rows)
            ? array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)))
            : [];
    }

    /**
     * Merges the three browser source registries into one source-class map.
     *
     * @return array<string, class-string> Source config class keyed by source key
     */
    private function mergedBrowserSources(): array
    {
        return Hilos::BROWSER_LISTS + Hilos::BROWSER_TABLES + Hilos::BROWSER_DATA;
    }

    /**
     * Merges the three page source registries, unioning each page's bindings.
     *
     * @return array<string, array<string, mixed>> Source binding map keyed by page
     */
    private function mergedPageSources(): array
    {
        $merged = [];
        foreach ([Hilos::PAGE_LISTS, Hilos::PAGE_TABLES, Hilos::PAGE_DATA] as $registry) {
            foreach ($registry as $page => $bindings) {
                $merged[$page] = ($merged[$page] ?? []) + $bindings;
            }
        }

        return $merged;
    }
}
