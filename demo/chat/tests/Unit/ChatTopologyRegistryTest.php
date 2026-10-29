<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Daemon\ChatWorkerManager;
use Demo\Chat\Pages\Hilos\TermsHistoryPage;
use Demo\Chat\Pages\Hilos\PrivacyHistoryPage;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Core\Page\PageSignalRouter;
use ReflectionMethod;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Demo\Chat\Pages\Hilos\Maintenance\MaintenanceCirclePage;
use Demo\Chat\Pages\Hilos\Maintenance\MaintenanceNodesPage;
use Demo\Chat\Pages\Hilos\Maintenance\MaintenanceWordsPage;
use Demo\Chat\Pages\Hilos\Maintenance\MaintenancePage;
use Hilos\Pages\Maintenance\DTO\MaintenancePassActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreCloseActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreOpenActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestorePassActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceDisableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceEnableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleRemoveActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;

use Demo\Chat\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\Chat\Pages\Hilos\Analytics\PersonPage;
use Demo\Chat\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\Chat\Pages\Hilos\Analytics\PeoplePage;
use Demo\Chat\Pages\Hilos\AnalyticsPage;

use Hilos\Core\Analytics\AnalyticsCommandConstants;
use Hilos\Auth\Code\DTO\AuthCodeMintSignalData;
use Hilos\Auth\Code\DTO\AuthCodeMintedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldExpiredSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldReleaseSignalData;
use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosLegalAgentDaemon;
use Demo\Chat\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\Data\UserMergeBrowserData;
use Demo\Chat\Browser\Data\UserPhotoBrowserData;
use Demo\Chat\Browser\Data\UserPresenceBrowserData;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Pages\UserPage as ChatUserPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\Chat\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\Chat\Pages\Hilos\Moderation\ModerationPage;
use Demo\Chat\Pages\Hilos\Moderation\ModerationKindPage;
use Demo\Chat\Pages\Hilos\Moderation\ModerationKindRulesPage;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogEntryReadActionDTO;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogReceiptReadActionDTO;
use Demo\Chat\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\Chat\Pages\Hilos\Legal\LegalPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentPage;
use Demo\Chat\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Chat\Pages\Hilos\Legal\LegalAcceptancesPage;
use Demo\Chat\Pages\Hilos\Legal\LegalSettingsPage;
use Demo\Chat\Pages\Hilos\AppearancePage;
use Demo\Chat\Tables\HilosLegal\HilosLegalAcceptancesTable;
use Hilos\Tables\Logs\HilosLogNodesTable;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Pages\Appearance\DTO\HilosAppearanceDefaultThemeSetActionDTO;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Hilos\Legal\Export\DTO\LegalAcceptancesExportForgetSignalData;
use Hilos\Legal\Export\LegalAcceptancesExportHttp;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelSettings;

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
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Pages\PageCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Status\StatusPage;
use Demo\Chat\Pages\Hilos\Status\StatusComponentsPage;
use Demo\Chat\Pages\Hilos\Status\StatusSubscribersPage;
use Demo\Chat\Pages\Hilos\Status\StatusWorksPage;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;
use Hilos\Pages\Status\DTO\HilosStatusIncidentActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusAddUpdateActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusRemoveSubscriberActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusCancelWorkActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusChangeWorkActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusScheduleWorkActionDTO;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Tables\Status\HilosStatusSubscribersTable;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Demo\Chat\Moderation\ChatModerationKindDirectory;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsChannelConfigurationPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsChannelFramePage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionCancelActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionCodeActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionOpenActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionStartActionDTO;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Auth\Library\DTO\AuthImpersonationOffApplySignalData;
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
use Hilos\Auth\Library\DTO\AuthSecondFactorDisableApplySignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorMissedSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorOffSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorTrustDaysApplySignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorSetupProvenSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\ProfileFlowStepSignalData;
use Hilos\Auth\Library\DTO\ProfileNameBeginSignalData;
use Hilos\Auth\Library\DTO\ProfileNameAdvanceSignalData;
use Hilos\Auth\Library\DTO\ProfileNameContinueSignalData;
use Hilos\Auth\Library\DTO\ProfileNameSetActionDTO;
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
use Hilos\Auth\Library\DTO\ThemeAdoptActionDTO;
use Hilos\Auth\Library\DTO\ThemePickActionDTO;
use Hilos\Auth\Library\DTO\TimeZonePickActionDTO;
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
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
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
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusRemoveSubscriberSignalData;
use Hilos\Status\DTO\StatusScheduleWorkSignalData;
use Hilos\Status\DTO\StatusChangeWorkSignalData;
use Hilos\Status\DTO\StatusCancelWorkSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterCronSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterHttpSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterStandingSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterMeasurementsSignalData;
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
use Hilos\Moderation\DTO\ModerationRuleAddSignalData;
use Hilos\Moderation\DTO\ModerationRuleDeleteSignalData;
use Hilos\Moderation\DTO\ModerationRuleUpdateSignalData;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleAddActionDTO;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleDeleteActionDTO;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleUpdateActionDTO;
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\Library\ModerationRulesLibraryAgentDaemon;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationAgentDaemon;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Tables\Moderation\HilosModerationRulesTable;
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
use Hilos\ProtectedMode\DTO\ManualMaintenanceEnableSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenanceDisableSignalData;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenancePassSignalData;
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
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use Hilos\Users\DTO\UserStepUpCreditDoneSignalData;
use Hilos\Users\DTO\UserStepUpCreditSignalData;
use Hilos\Users\DTO\UserStepUpRecordDoneSignalData;
use Hilos\Users\DTO\UserStepUpRecordSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use Hilos\HilosException;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
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
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use ReflectionClass;
use Hilos\Core\Agent\Daemon\StatusAgentDaemon;
use Hilos\Core\Agent\TopologyAgentFactory;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\Status\DTO\StatusConfirmSubscriptionActionDTO;
use Hilos\Status\DTO\StatusSubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribePreviewActionDTO;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusSettingsCatalog;

/**
 * Guards the project-level chat topology registry.
 */
final class ChatTopologyRegistryTest extends TestCase
{
    public function testPublicLegalHistoryUsesIndexPagesAndSeparatePublicTables(): void
    {
        self::assertSame(TermsHistoryPage::class, Hilos::PAGES[TermsHistoryPage::PAGE]);
        self::assertSame(PrivacyHistoryPage::class, Hilos::PAGES[PrivacyHistoryPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[TermsHistoryPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[PrivacyHistoryPage::PAGE]);
        self::assertSame(HilosTermsHistoryTable::class, Hilos::TABLES[ChatTableContext::hilosTermsHistory]);
        self::assertSame(HilosPrivacyHistoryTable::class, Hilos::TABLES[ChatTableContext::hilosPrivacyHistory]);
        self::assertSame([ChatTableContext::hilosTermsHistory => []], Hilos::PAGE_TABLES[TermsHistoryPage::PAGE]);
        self::assertSame([ChatTableContext::hilosPrivacyHistory => []], Hilos::PAGE_TABLES[PrivacyHistoryPage::PAGE]);
        self::assertSame([], TermsHistoryPage::ACTIONS);
        self::assertSame([], PrivacyHistoryPage::ACTIONS);
        self::assertSame('hilos_terms', Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_TERMS_REVISION_TEXT]);
        self::assertSame('hilos_privacy', Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_TERMS_REVISION_TEXT]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT]);
    }

    public function testSessionNameChangesHaveOneAuthSessionOwner(): void
    {
        self::assertContains(HilosFeature::AUTH, Hilos::features());
        $owners = [];
        foreach (Hilos::AGENTS as $type => $entry) {
            $workerClass = AgentRegistry::workerClass($entry);
            if ($workerClass === null) {
                continue;
            }
            $claims = OwnershipDeclaration::rtCollectionsOf($workerClass);
            if (!array_key_exists(HilosProfileNameChange::RT_COLLECTION, $claims)) {
                continue;
            }
            self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
            $owners[$type] = $claims[HilosProfileNameChange::RT_COLLECTION]->asList();
        }
        self::assertSame([
            HilosAgentType::HILOS_SESSIONS_LIBRARY => TruthSourceOperation::ALL,
        ], $owners);
    }

    public function testLetterFrameUsesTheExistingIndexOwnerWithoutTablesOrMutations(): void
    {
        self::assertSame(CommunicationsChannelFramePage::class, Hilos::PAGES[CommunicationsChannelFramePage::PAGE]);
        self::assertSame(CommunicationsChannelConfigurationPage::SUBSCRIPTION_AGENT_TYPE,
            Hilos::getPageRoutes()[CommunicationsChannelFramePage::PAGE]);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL_FRAME,
        ], CommunicationsChannelFramePage::BROWSER);
        self::assertSame([], CommunicationsChannelFramePage::ACTIONS);
        self::assertSame([], CommunicationsChannelFramePage::SIGNALS);
        self::assertArrayNotHasKey(CommunicationsChannelFramePage::PAGE, Hilos::PAGE_TABLES);
    }

    /** The shared Status owner is a permanent singleton placed on a regular worker. */
    public function testStatusOwnerUsesTheFrameworkPairAndFactories(): void
    {
        self::assertSame([
            HilosFeature::SETTINGS,
            HilosFeature::I18N,
            HilosFeature::HILOS_USERS,
            HilosFeature::BACKUP,
            HilosFeature::LOGS,
            HilosFeature::DAEMON,
            HilosFeature::STATUS,
            HilosFeature::NOTIFICATIONS,
            HilosFeature::NOTIFICATION_DELIVERY,
            HilosFeature::AUTH,
            HilosFeature::AUTH_THROTTLE,
            HilosFeature::CODE_CHANNELS,
            HilosFeature::FILES,
            HilosFeature::UPLOADS,
            HilosFeature::IMAGES,
            HilosFeature::PROFILE_PHOTO,
            HilosFeature::MODERATION,
            HilosFeature::ANALYTICS,
            HilosFeature::CHANGE_LOG,
        ], Hilos::featuresOf(Hilos::class));
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_STATUS];
        self::assertSame([
            AgentRegistryKey::WORKER => StatusAgent::class,
            AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::CLUSTER,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ], $entry);
        self::assertFalse(AgentRegistry::requiresIndex($entry));
        self::assertNull(AgentRegistry::idleTimeout($entry));
        self::assertSame(StatusAgent::class, TopologyAgentFactory::createWorker(Hilos::class, HilosAgentType::HILOS_STATUS, null)::class);
        $daemon = TopologyAgentFactory::createDaemon(Hilos::class, HilosAgentType::HILOS_STATUS, null);
        self::assertSame(StatusAgentDaemon::class, $daemon::class);
        self::assertFalse($daemon->requiresMonopolisticProcess());
    }

    public function testWorkerFactoryReadsEachParkingAgentsVerdictDeclaration(): void
    {
        $manager = new ReflectionClass(ChatWorkerManager::class)->newInstanceWithoutConstructor();
        $factory = new ReflectionMethod(ChatWorkerManager::class, 'createPageSignalRouter');
        $name = new ReflectionProperty(PageSignalRouter::class, 'throttleVerdictSignal');
        $status = $factory->invoke($manager, new StatusAgent());
        $users = $factory->invoke($manager, TopologyAgentFactory::createWorker(Hilos::class, HilosAgentType::HILOS_USERS_LIBRARY, null));
        self::assertInstanceOf(PageSignalRouter::class, $status);
        self::assertInstanceOf(PageSignalRouter::class, $users);
        self::assertSame(HilosSignalConstants::STATUS_THROTTLE_VERDICT, $name->getValue($status));
        self::assertSame(HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT, $name->getValue($users));
    }

    /** Status owns typed guest actions beside the collector response and internal writes. */
    public function testStatusConsumerAndInternalOperationsHaveTypedRoutes(): void
    {
        self::assertSame([
            HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_PUBLICATION => StatusCancelPublicationSignalData::class,
            HilosSignalConstants::STATUS_PUBLISH_NOW => StatusPublishNowSignalData::class,
            HilosSignalConstants::STATUS_ADD_UPDATE => StatusAddUpdateSignalData::class,
            HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER => StatusRemoveSubscriberSignalData::class,
            HilosSignalConstants::STATUS_SCHEDULE_WORK => StatusScheduleWorkSignalData::class,
            HilosSignalConstants::STATUS_CHANGE_WORK => StatusChangeWorkSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_WORK => StatusCancelWorkSignalData::class,
            HilosSignalConstants::STATUS_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
        ], StatusAgent::AGENT_SIGNALS);
        self::assertSame(DaemonPictureWatchSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH]);
        self::assertSame(DaemonClusterPicturePortionSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION]);
        self::assertSame(StatusCancelPublicationSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_CANCEL_PUBLICATION]);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, Hilos::getActionAgentRoutes());
        self::assertNotContains(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, StatusAgent::AUTH_ACTIONS);
        self::assertSame(StatusPublishNowSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_PUBLISH_NOW]);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_PUBLISH_NOW, Hilos::getActionAgentRoutes());
        self::assertNotContains(HilosSignalConstants::STATUS_PUBLISH_NOW, StatusAgent::AUTH_ACTIONS);
        self::assertSame(StatusAddUpdateSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_ADD_UPDATE]);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_ADD_UPDATE, Hilos::getActionAgentRoutes());
        self::assertNotContains(HilosSignalConstants::STATUS_ADD_UPDATE, StatusAgent::AUTH_ACTIONS);
        self::assertSame([
            HilosSignalConstants::STATUS_SUBSCRIBE => StatusSubscribeActionDTO::class,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => StatusConfirmSubscriptionActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => StatusUnsubscribePreviewActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE => StatusUnsubscribeActionDTO::class,
        ], StatusAgent::AGENT_ACTIONS);
        self::assertSame([
            HilosSignalConstants::STATUS_SUBSCRIBE,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW,
            HilosSignalConstants::STATUS_UNSUBSCRIBE,
        ], StatusAgent::THROTTLED_ACTIONS);
        self::assertSame([], StatusAgent::AUTH_ACTIONS);
        self::assertSame([HilosDbContext::sessions], StatusAgent::READS_DB);
        self::assertSame([HilosClusterNode::RT_COLLECTION], StatusAgent::READS_RT);
        foreach ([
            HilosSignalConstants::STATUS_SUBSCRIBE => StatusSubscribeActionDTO::class,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => StatusConfirmSubscriptionActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => StatusUnsubscribePreviewActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE => StatusUnsubscribeActionDTO::class,
        ] as $action => $dto) {
            self::assertSame(HilosAgentType::HILOS_STATUS, Hilos::getAgentActionRoutes()[$action]);
            self::assertSame($dto, Hilos::getAgentActionDtoRoutes()[$action]);
        }
    }

    /** The project inherits Service and merges the live publication-delay setting. */
    public function testStatusComponentsAndSettingsUseFrameworkDefaults(): void
    {
        $components = iterator_to_array(Hilos::statusComponentsOf(Hilos::class));
        self::assertSame(['service'], array_keys($components));
        $service = $components['service'];
        self::assertSame([
            'service', 'Service', null, HilosAgentType::HILOS_INDEX, AgentScope::CLUSTER, AgentPlacement::LEADER,
        ], [
            $service->componentKey, $service->name, $service->description, $service->agentType, $service->scope, $service->placement,
        ]);
        self::assertSame([
            SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
            SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 300,
            SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            SettingsCatalogConstants::CATALOG_ENTRY_RULE => NonNegativeIntegerRule::class,
        ], SettingsCatalog::getCatalog()[StatusSettingsCatalog::PUBLISH_DELAY_SECONDS]);
    }

    /** Status requires its storage and all project-bound admin pages. */
    public function testStatusRequirementsAndSchemaSelectionAreComplete(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::STATUS);
        $requirements = $definition->requirements();
        self::assertSame(
            [HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY, HilosFeature::AUTH_THROTTLE], $requirements->requires,
        );
        self::assertSame([HilosAgentType::HILOS_STATUS], $requirements->requiredAgents);
        self::assertSame([HilosAgentType::HILOS_MAIL], $requirements->requiredSharedAgents);
        self::assertSame([StatusSettingsCatalog::class], $requirements->requiredCatalogFragments);
        self::assertSame([
            'hilos_status_incident', 'hilos_status_incident_update', 'hilos_status_subscriber',
            'hilos_status_work', 'hilos_status_work_component', 'hilos_status_maintenance_period',
        ], $requirements->requiredDbTables);
        self::assertSame([AbstractHilosStatusPage::class, AbstractHilosStatusComponentsPage::class,
                AbstractHilosStatusSubscribersPage::class, AbstractHilosStatusWorksPage::class],
            $requirements->requiredPages);
        self::assertSame([HilosStatusComponentsTable::class, HilosStatusSubscribersTable::class, HilosStatusWorksTable::class],
            $requirements->requiredTables);
        self::assertSame([AbstractHilosStatusComponentsPage::class => HilosStatusComponentsTable::class,
            AbstractHilosStatusSubscribersPage::class => HilosStatusSubscribersTable::class,
            AbstractHilosStatusWorksPage::class => HilosStatusWorksTable::class], $requirements->requiredPageTables);
        self::assertTrue($definition->mountsRuntime());

        $statusUnits = [];
        foreach ((new FrameworkSchemaCatalog())->selectedUnits(Hilos::class) as $unit) {
            if (str_starts_with($unit->key, 'status_')) {
                $statusUnits[$unit->key] = [$unit->tables, $unit->dependencies, $unit->connection];
            }
        }
        self::assertSame([
            'status_incident' => [['hilos_status_incident'], [], 0],
            'status_incident_update' => [['hilos_status_incident_update'], ['status_incident'], 0],
            'status_subscriber' => [['hilos_status_subscriber'], [], 0],
            'status_work' => [['hilos_status_work'], [], 0],
            'status_work_component' => [['hilos_status_work_component'], ['status_work'], 0],
            'status_maintenance_period' => [['hilos_status_maintenance_period'], ['status_work'], 0],
        ], $statusUnits);
    }

    /** Pins the Daemon section's pages, owners and placement in the chat topology. */
    public function testDaemonSectionActivation(): void
    {
        self::assertContains(HilosFeature::DAEMON, Hilos::featuresOf(Hilos::class));

        foreach ([
            DaemonPage::class,
            DaemonPlacementPage::class,
            DaemonNodePage::class,
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
        self::assertArrayNotHasKey(DaemonNodePage::PAGE, Hilos::PAGE_TABLES);
        self::assertArrayNotHasKey(DaemonPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame(DaemonEnvPage::class, Hilos::PAGES[DaemonEnvPage::PAGE]);
        self::assertSame(HilosAgentType::HILOS_DAEMON_NODE, Hilos::getPageRoutes()[DaemonEnvPage::PAGE]);
        self::assertSame(DaemonEnvOrphansPage::class, Hilos::PAGES[DaemonEnvOrphansPage::PAGE]);
        self::assertSame(HilosAgentType::HILOS_DAEMON_NODE, Hilos::getPageRoutes()[DaemonEnvOrphansPage::PAGE]);

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
        self::assertSame(HilosDaemonEnvKeysTable::class, Hilos::TABLES[ChatTableContext::hilosDaemonEnvKeys]);
        self::assertSame(HilosDaemonEnvOrphansTable::class, Hilos::TABLES[ChatTableContext::hilosDaemonEnvOrphans]);
        self::assertSame(HilosDaemonAgentsTable::class, Hilos::TABLES[ChatTableContext::hilosDaemonAgents]);
        self::assertSame(HilosDaemonEnvMismatchTable::class, Hilos::TABLES[ChatTableContext::hilosDaemonEnvMismatch]);
        self::assertSame(HilosDaemonHttpRoutesTable::class, Hilos::TABLES[ChatTableContext::hilosDaemonHttpRoutes]);
        self::assertSame(HilosDaemonPlacementTable::class, Hilos::TABLES[ChatTableContext::hilosDaemonPlacement]);
        self::assertSame([ChatTableContext::hilosDaemonCron => []], Hilos::PAGE_TABLES[DaemonCronPage::PAGE]);
        self::assertSame([ChatTableContext::hilosDaemonWorkers => []], Hilos::PAGE_TABLES[DaemonWorkersPage::PAGE]);
        self::assertSame([
            ChatTableContext::hilosDaemonEnvKeys => [],
        ], Hilos::PAGE_TABLES[DaemonEnvPage::PAGE]);
        self::assertSame([
            ChatTableContext::hilosDaemonEnvOrphans => [],
        ], Hilos::PAGE_TABLES[DaemonEnvOrphansPage::PAGE]);
        self::assertSame([ChatTableContext::hilosDaemonPlacement => []], Hilos::PAGE_TABLES[DaemonPlacementPage::PAGE]);
        self::assertSame([ChatTableContext::hilosDaemonAgents => []], Hilos::PAGE_TABLES[DaemonAgentsPage::PAGE]);
        self::assertSame([
            ChatTableContext::hilosDaemonEnvMismatch => [],
        ], Hilos::PAGE_TABLES[DaemonEnvMismatchPage::PAGE]);
        self::assertSame([ChatTableContext::hilosDaemonHttpRoutes => []], Hilos::PAGE_TABLES[DaemonHttpServerPage::PAGE]);
    }

    /** The change-log agent serves all three journal table viewports. */
    public function testChangeLogSectionTables(): void
    {
        self::assertTrue(Hilos::hasFeature(HilosFeature::CHANGE_LOG));
        self::assertSame(ChangeLogDashboardPage::class, Hilos::PAGES[ChangeLogDashboardPage::PAGE]);
        self::assertSame(ChangeLogTablesPage::class, Hilos::PAGES[ChangeLogTablesPage::PAGE]);
        self::assertSame(ChangeLogTablePage::class, Hilos::PAGES[ChangeLogTablePage::PAGE]);
        self::assertSame(DemoHilosChangeLogAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::HILOS_CHANGE_LOG]));
        self::assertSame(DemoHilosChangeLogAgentDaemon::class, AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::HILOS_CHANGE_LOG]));
        self::assertTrue((new DemoHilosChangeLogAgentDaemon())->requiresMonopolisticProcess());
        self::assertSame(HilosChangeLogFeedTable::class, Hilos::TABLES[ChatTableContext::hilosChangeLogFeed]);
        self::assertSame(HilosChangeLogHistoryTable::class, Hilos::TABLES[ChatTableContext::hilosChangeLogHistory]);
        self::assertSame(HilosChangeLogTablesTable::class, Hilos::TABLES[ChatTableContext::hilosChangeLogTables]);
        $this->assertSame(
            HilosAnalyticsActionsTable::class,
            Hilos::TABLES[ChatTableContext::hilosAnalyticsActions],
        );
        $this->assertSame(
            HilosAnalyticsBrowserSessionsTable::class,
            Hilos::TABLES[ChatTableContext::hilosAnalyticsBrowserSessions],
        );
        self::assertSame([ChatTableContext::hilosChangeLogFeed => []], Hilos::PAGE_TABLES[ChangeLogFeedPage::PAGE]);
        self::assertArrayNotHasKey(ChangeLogDashboardPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogFeedPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogNoisyPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_FEED,
            ChangeLogFeedPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_NOISY,
            ChangeLogNoisyPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame([], ChangeLogDashboardPage::ACTIONS);
        self::assertSame(ChangeLogFeedPage::class, Hilos::PAGES[ChangeLogFeedPage::PAGE]);
        self::assertSame(ChangeLogNoisyPage::class, Hilos::PAGES[ChangeLogNoisyPage::PAGE]);
        self::assertSame(HilosChangeLogNoisyColumnsTable::class, Hilos::TABLES[ChatTableContext::hilosChangeLogNoisyColumns]);
        self::assertSame([ChatTableContext::hilosChangeLogNoisyColumns => []], Hilos::PAGE_TABLES[ChangeLogNoisyPage::PAGE]);
        self::assertSame([ChatTableContext::hilosChangeLogHistory => []], Hilos::PAGE_TABLES[ChangeLogTableHistoryPage::PAGE]);
        self::assertArrayNotHasKey(ChangeLogTablePage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame([ChatTableContext::hilosChangeLogTableColumns => []],
            Hilos::PAGE_TABLES[ChangeLogTableColumnsPage::PAGE]);
        self::assertSame(HilosChangeLogTableColumnsTable::class, Hilos::TABLES[ChatTableContext::hilosChangeLogTableColumns]);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogTableColumnsPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame([ChatTableContext::hilosChangeLogTableTriggers => []],
            Hilos::PAGE_TABLES[ChangeLogTableTriggersPage::PAGE]);
        self::assertSame(HilosChangeLogTableTriggersTable::class, Hilos::TABLES[ChatTableContext::hilosChangeLogTableTriggers]);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogTableTriggersPage::SUBSCRIPTION_AGENT_TYPE);
        foreach ([
            ChangeLogTablePage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE,
            ChangeLogTableColumnsPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_COLUMNS,
            ChangeLogTableTriggersPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_TRIGGERS,
            ChangeLogTableHistoryPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_HISTORY,
        ] as $page => $signal) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_CHANGE_LOG, Hilos::getPageRoutes()[$page::PAGE]);
            self::assertSame($signal, $page::BROWSER[BrowserConfigKey::SIGNAL]);
            self::assertSame([], $page::ACTIONS);
        }
        self::assertSame([ChatTableContext::hilosChangeLogTables => []], Hilos::PAGE_TABLES[ChangeLogTablesPage::PAGE]);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogDashboardPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogTablePage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogTablesPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame([
            HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ => HilosChangeLogReceiptReadActionDTO::class,
            HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ => HilosChangeLogEntryReadActionDTO::class,
        ], ChangeLogFeedPage::ACTIONS);
        self::assertSame(HilosChangeLogReceiptReadActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ]);
        self::assertSame(HilosChangeLogEntryReadActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ]);
    }

    /** Appearance reuses the index agent and the framework's self-snapshot table. */
    public function testAppearanceAdminFeatureIsActivated(): void
    {
        self::assertSame(AppearancePage::class, Hilos::PAGES[AppearancePage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, AppearancePage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(
            HilosPageConstants::HILOS_APPEARANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET],
        );
        self::assertSame(
            HilosPageConstants::HILOS_APPEARANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET],
        );
        self::assertSame(
            HilosAppearanceDefaultThemeSetActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET],
        );
        self::assertSame(
            HilosAppearanceSettingsTable::class,
            Hilos::TABLES[ChatTableContext::hilosAppearanceSettings],
        );
        self::assertSame(
            [ChatTableContext::hilosAppearanceSettings => []],
            Hilos::PAGE_TABLES[AppearancePage::PAGE],
        );
    }

    /**
     * The chat user page is drawn from three data sources over the same person: the runtime
     * presence, the merge the account may have gone into (HIL-1292), and the person's photo
     * (HIL-1300) - each bound to the page by the same route parameter as the first.
     */
    public function testTheUserPageCarriesThePresenceTheMergeAndThePhotoData(): void
    {
        $this->assertSame(UserPresenceBrowserData::class, Hilos::BROWSER_DATA[UserPresenceBrowserData::DATA]);
        $this->assertSame(UserMergeBrowserData::class, Hilos::BROWSER_DATA[UserMergeBrowserData::DATA]);
        $this->assertSame(UserPhotoBrowserData::class, Hilos::BROWSER_DATA[UserPhotoBrowserData::DATA]);
        $binding = [
            BrowserParamKey::PARAMS => [
                UserPageSubscribeParams::USER_ID => ChatBrowserRef::USER_ID,
            ],
        ];
        $this->assertSame(
            [
                UserPresenceBrowserData::DATA => $binding,
                UserMergeBrowserData::DATA => $binding,
                UserPhotoBrowserData::DATA => $binding,
            ],
            Hilos::PAGE_DATA[ChatUserPage::PAGE],
        );
    }

    /** Legal administration is bound to its own worker, page routes and tables. */
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

    public function testNotificationDeliverySettingsDefaults(): void
    {
        $catalog = SettingsCatalog::getCatalog();
        $emailKey = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);
        $this->assertArrayHasKey($emailKey, $catalog);
        $this->assertTrue($catalog[$emailKey][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
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

    /** Circle alone binds the verifier table and its mutations; Words binds the words table without actions; Overview keeps the manual actions. */
    public function testMaintenanceCircleUsesTheIndexAgentAndOwnsTheTableBinding(): void
    {
        $this->assertSame(MaintenanceCirclePage::class, Hilos::PAGES[HilosPageConstants::HILOS_MAINTENANCE_CIRCLE]);
        $this->assertSame(MaintenanceWordsPage::class, Hilos::PAGES[HilosPageConstants::HILOS_MAINTENANCE_WORDS]);
        $this->assertSame(MaintenanceNodesPage::class, Hilos::PAGES[HilosPageConstants::HILOS_MAINTENANCE_NODES]);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[MaintenanceCirclePage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[MaintenanceWordsPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[MaintenanceNodesPage::PAGE]);
        $this->assertSame(HilosMaintenanceWordsTable::class, Hilos::TABLES[ChatTableContext::hilosMaintenanceWords]);
        $this->assertSame(HilosMaintenanceNodesTable::class, Hilos::TABLES[ChatTableContext::hilosMaintenanceNodes]);
        $this->assertSame([], MaintenanceWordsPage::ACTIONS);
        $this->assertSame([], MaintenanceNodesPage::ACTIONS);
        $this->assertSame([
            HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => MaintenanceCircleAddActionDTO::class,
            HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => MaintenanceCircleRemoveActionDTO::class,
        ], MaintenanceCirclePage::ACTIONS);
        $this->assertSame([
            HilosSignalConstants::MAINTENANCE_ENABLE => MaintenanceEnableActionDTO::class,
            HilosSignalConstants::MAINTENANCE_DISABLE => MaintenanceDisableActionDTO::class,
            HilosSignalConstants::MAINTENANCE_PASS => MaintenancePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS => MaintenanceRestorePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_OPEN => MaintenanceRestoreOpenActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE => MaintenanceRestoreCloseActionDTO::class,
        ], MaintenancePage::ACTIONS);
        $this->assertSame(
            [ChatTableContext::hilosVerifierCircle => []],
            Hilos::PAGE_TABLES[MaintenanceCirclePage::PAGE],
        );
        $this->assertSame(
            [ChatTableContext::hilosMaintenanceWords => []],
            Hilos::PAGE_TABLES[MaintenanceWordsPage::PAGE],
        );
        $this->assertSame(
            [ChatTableContext::hilosMaintenanceNodes => []],
            Hilos::PAGE_TABLES[MaintenanceNodesPage::PAGE],
        );
        $this->assertArrayNotHasKey(MaintenancePage::PAGE, Hilos::PAGE_TABLES);
    }

    /** Configuration owns only the field-table subscription; the existing channel page keeps mutation routing. */
    public function testCommunicationsConfigurationUsesTheIndexAgentAndOwnsOnlyTheFieldsBinding(): void
    {
        self::assertSame(CommunicationsChannelConfigurationPage::class, Hilos::PAGES[CommunicationsChannelConfigurationPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[CommunicationsChannelConfigurationPage::PAGE]);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
        ], CommunicationsChannelConfigurationPage::BROWSER);
        self::assertSame([], CommunicationsChannelConfigurationPage::ACTIONS);
        self::assertSame([], CommunicationsChannelConfigurationPage::SIGNALS);
        self::assertSame([
            ChatTableContext::hilosCommunicationsChannelFields => [],
        ], Hilos::PAGE_TABLES[CommunicationsChannelConfigurationPage::PAGE]);
        self::assertArrayNotHasKey(CommunicationsChannelPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame([
            ChatTableContext::hilosCommunicationsChannels => [],
        ], Hilos::PAGE_TABLES[CommunicationsPage::PAGE]);
        self::assertSame([
            ChatTableContext::hilosNotificationDeliveries => [],
        ], Hilos::PAGE_TABLES[CommunicationsDeliveriesPage::PAGE]);

        foreach ([
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST,
        ] as $action) {
            self::assertSame(CommunicationsChannelPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
        }
        self::assertSame(
            CommunicationsChannelPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE],
        );
        self::assertSame(
            CommunicationsDeliveriesPage::PAGE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY],
        );
    }

    /** Catalog overrides retain every framework field of the adopted owners, including Security. */
    public function testCatalogAdoptsUserPersonAndBothCommunicationsOwnersWithCompleteEntries(): void
    {
        Hilos::initBrowser();
        try {
            self::assertSame(PageCatalog::class, Hilos::getPageCatalogClass());
        } finally {
            Hilos::resetBrowser();
        }
        $pages = PageCatalog::pages();
        self::assertSame(
            array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_LOGS],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            $pages[HilosPageConstants::HILOS_LOGS],
        );
        self::assertSame(
            array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            $pages[HilosPageConstants::HILOS_CHANGE_LOG],
        );
        self::assertSame(
            array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG_TABLE],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            $pages[HilosPageConstants::HILOS_CHANGE_LOG_TABLE],
        );
        self::assertSame(
            array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SECURITY],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            $pages[HilosPageConstants::HILOS_SECURITY],
        );
        self::assertSame(
            array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            $pages[HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER],
        );
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Communications',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Email, SMS, push, and chat delivery channels.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-envelope-paper',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], $pages[HilosPageConstants::HILOS_COMMUNICATIONS]);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Channel',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single communication channel and its configuration.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_COMMUNICATIONS,
            PageCatalogConstants::CATALOG_ENTRY_ENTITY => true,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Channel',
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-envelope-paper',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], $pages[HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL]);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Appearance',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Theme switching and the default theme.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-palette',
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Appearance',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], $pages[HilosPageConstants::HILOS_APPEARANCE]);
        self::assertSame(
            array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_BACKUP],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            $pages[HilosPageConstants::HILOS_BACKUP],
        );
        self::assertSame([
            HilosPageConstants::HILOS_USER,
            HilosPageConstants::HILOS_LOGS,
            HilosPageConstants::HILOS_CHANGE_LOG,
            HilosPageConstants::HILOS_CHANGE_LOG_TABLE,
            HilosPageConstants::HILOS_ANALYTICS_PERSON,
            HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION,
            HilosPageConstants::HILOS_COMMUNICATIONS,
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosPageConstants::HILOS_SECURITY,
            HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER,
            HilosPageConstants::HILOS_APPEARANCE,
            HilosPageConstants::HILOS_BACKUP,
            PageConstants::ADMIN_BOTS,
        ], array_keys($pages));
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Bots',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Library bots: profiles, status, and behavior.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-robot',
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Bots',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], $pages[PageConstants::ADMIN_BOTS]);
        self::assertSame([[
            PageCatalogConstants::SECTION_TITLE => 'Chat administration',
            PageCatalogConstants::SECTION_DESCRIPTION => 'Application-specific admin areas for the chat demo.',
            PageCatalogConstants::SECTION_ITEMS => [PageConstants::ADMIN_BOTS],
        ]], PageCatalog::dashboardSections());
    }

    /** Status uses the existing index agent, one read-only window and the ADMIN handover route. */
    public function testStatusPagesTablesActionsAndReplyAreWired(): void
    {
        self::assertSame(StatusPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS]);
        self::assertSame(StatusComponentsPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS_COMPONENTS]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusComponentsPage::PAGE]);
        self::assertSame(HilosStatusComponentsTable::class, Hilos::TABLES[HilosStatusComponentsTable::TABLE]);
        self::assertSame([HilosStatusComponentsTable::TABLE => []], Hilos::PAGE_TABLES[StatusComponentsPage::PAGE]);
        self::assertArrayNotHasKey(StatusPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame([], StatusComponentsPage::ACTIONS);
        self::assertSame(StatusWorksPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS_WORKS]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusWorksPage::PAGE]);
        self::assertSame(HilosStatusWorksTable::class, Hilos::TABLES[HilosStatusWorksTable::TABLE]);
        self::assertSame([HilosStatusWorksTable::TABLE => []], Hilos::PAGE_TABLES[StatusWorksPage::PAGE]);
        self::assertSame([
            HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE => HilosStatusScheduleWorkActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_WORK_CHANGE => HilosStatusChangeWorkActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_WORK_CANCEL => HilosStatusCancelWorkActionDTO::class,
        ], StatusWorksPage::ACTIONS);
        foreach (StatusWorksPage::ACTIONS as $action => $dto) {
            self::assertSame(StatusWorksPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
            self::assertSame($dto, Hilos::getActionDtoRoutes()[$action]);
            self::assertNotContains($action, StatusWorksPage::READING_ACTIONS);
        }
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_WORKS,
            StatusWorksPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(StatusWorksPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE]);
        self::assertSame(AgentType::HILOS_INDEX,
            Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE]);
        self::assertSame(HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE]);
        self::assertSame(StatusSubscribersPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS_SUBSCRIBERS]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusSubscribersPage::PAGE]);
        self::assertSame(HilosStatusSubscribersTable::class, Hilos::TABLES[HilosStatusSubscribersTable::TABLE]);
        self::assertSame([HilosStatusSubscribersTable::TABLE => []], Hilos::PAGE_TABLES[StatusSubscribersPage::PAGE]);
        self::assertSame([
            HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE => HilosStatusRemoveSubscriberActionDTO::class,
        ], StatusSubscribersPage::ACTIONS);
        self::assertSame(StatusSubscribersPage::PAGE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE]);
        self::assertSame(AgentType::HILOS_INDEX,
            Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE]);
        self::assertSame(HilosStatusRemoveSubscriberActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE]);
        self::assertNotContains(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, StatusSubscribersPage::READING_ACTIONS);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_SUBSCRIBERS,
            StatusSubscribersPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(StatusSubscribersPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE]);
        self::assertSame(AgentType::HILOS_INDEX,
            Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE]);
        self::assertSame(HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE]);
        self::assertSame([
            HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION => HilosStatusIncidentActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW => HilosStatusIncidentActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_ADD_UPDATE => HilosStatusAddUpdateActionDTO::class,
        ], StatusPage::ACTIONS);
        foreach (StatusPage::ACTIONS as $action => $dto) {
            self::assertSame(StatusPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
            self::assertSame($dto, Hilos::getActionDtoRoutes()[$action]);
            self::assertNotContains($action, StatusPage::READING_ACTIONS);
        }
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS, StatusPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_COMPONENTS,
            StatusComponentsPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(StatusPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WRITE_DONE]);
        self::assertSame(AgentType::HILOS_INDEX,
            Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WRITE_DONE]);
        self::assertSame(HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WRITE_DONE]);
    }

    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    /** The numeric browser-session page uses the Analytics owner and its existing action table. */
    public function testAnalyticsBrowserSessionPageIsWired(): void
    {
        self::assertSame(BrowserSessionPage::class, Hilos::PAGES[BrowserSessionPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[BrowserSessionPage::PAGE]);
        self::assertSame([ChatTableContext::hilosAnalyticsActions => []], Hilos::PAGE_TABLES[BrowserSessionPage::PAGE]);
    }

    /** The period sessions list uses its own table without changing the person's sessions table. */
    public function testAnalyticsBrowserSessionsPageIsWired(): void
    {
        self::assertSame(BrowserSessionsPage::class, Hilos::PAGES[BrowserSessionsPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[BrowserSessionsPage::PAGE]);
        self::assertSame([
            ChatTableContext::hilosAnalyticsBrowserSessionList => [],
        ], Hilos::PAGE_TABLES[BrowserSessionsPage::PAGE]);
        self::assertSame(
            HilosAnalyticsBrowserSessionListTable::class,
            Hilos::TABLES[ChatTableContext::hilosAnalyticsBrowserSessionList],
        );
        self::assertSame([
            ChatTableContext::hilosAnalyticsBrowserSessions => [],
        ], Hilos::PAGE_TABLES[PersonPage::PAGE]);
    }

    /** The People page binds its own table to the existing Analytics subscription owner. */
    public function testAnalyticsPeoplePageIsWired(): void
    {
        self::assertSame(PeoplePage::class, Hilos::PAGES[PeoplePage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[PeoplePage::PAGE]);
        self::assertSame([ChatTableContext::hilosAnalyticsPeople => []], Hilos::PAGE_TABLES[PeoplePage::PAGE]);
        self::assertSame(HilosAnalyticsPeopleTable::class, Hilos::TABLES[ChatTableContext::hilosAnalyticsPeople]);
        self::assertArrayNotHasKey(ChatTableContext::hilosAnalyticsPeople, Hilos::PAGE_TABLES[PersonPage::PAGE]);
        self::assertArrayNotHasKey(AnalyticsPage::PAGE, Hilos::PAGE_TABLES);
    }

    /** The person's sessions page uses the Analytics owner and its existing sessions table. */
    public function testAnalyticsPersonPageIsWired(): void
    {
        self::assertSame(PersonPage::class, Hilos::PAGES[PersonPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[PersonPage::PAGE]);
        self::assertSame([ChatTableContext::hilosAnalyticsBrowserSessions => []], Hilos::PAGE_TABLES[PersonPage::PAGE]);
    }

    /** The Daemon environment page names the node whose replica serves it. */
    public function testOnlyDaemonEnvironmentPageUsesNodeAddressedSubscription(): void
    {
        $routes = Hilos::getPageAgentIndexRoutes();
        $this->assertSame([DaemonEnvPage::PAGE, DaemonEnvOrphansPage::PAGE], array_keys($routes));
        foreach ([DaemonEnvPage::PAGE, DaemonEnvOrphansPage::PAGE] as $page) {
            $this->assertSame('node_param', $routes[$page]->source->value);
            $this->assertSame('nodeId', $routes[$page]->param);
            $this->assertSame(HilosAgentType::HILOS_DAEMON, $routes[$page]->fallbackAgentType);
        }
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

        // The seven libraries, the backup agent, the delivery shards, the log aggregator and the auth throttle: one
        // instance cluster-wide (per shard index, for the shards), on the node policy picks. An
        // entity library is placed rather than pinned by rule, and each has a reason
        // of its own besides: minting an account is a claim one process holds wherever it sits,
        // every handshake touches sessions, every worker emits into notifications, every published
        // file is bound and swept by one owner, every admin screen writes settings through one
        // hand, the i18n reference has one writer, and so do the moderation rules, and the checks
        // of everything people publish go through one moderator. The uploads and images agents are placed the same
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
            HilosAgentType::HILOS_MODERATION_RULES_LIBRARY,
            HilosAgentType::HILOS_MODERATOR,
            HilosAgentType::HILOS_BACKUP,
            AgentType::HILOS_MAIL,
            AgentType::HILOS_SMS,
            AgentType::HILOS_PUSH,
            HilosAgentType::HILOS_LOG_AGGREGATOR,
            HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosAgentType::HILOS_STATUS,
            HilosAgentType::HILOS_ANALYTICS_WRITER,
            AgentType::HILOS_AUTH_THROTTLE,
        ], $policyPlaced);
    }

    /**
     * The chat switches moderation on (HIL-1625): the rules library of HIL-1624 and the cluster's one
     * moderator are the framework pairs themselves, without a project subclass, each placed once for
     * the cluster, and the moderation settings sit in the chat's own catalog because that is the one
     * the activation check reads. The chat's own moderator keeps its place until the photo path
     * moves off it.
     */
    public function testModerationIsOnWithItsRulesLibraryAndItsModerator(): void
    {
        $this->assertContains(HilosFeature::MODERATION, Hilos::featuresOf(Hilos::class));
        $this->assertSame(HilosModerationKindsTable::TABLE, ChatTableContext::hilosModerationKinds);
        $this->assertSame(HilosModerationKindsTable::class, Hilos::TABLES[ChatTableContext::hilosModerationKinds]);
        $this->assertSame(HilosModerationRulesTable::TABLE, ChatTableContext::hilosModerationRules);
        $this->assertSame(HilosModerationRulesTable::class, Hilos::TABLES[ChatTableContext::hilosModerationRules]);
        self::assertSame(ModerationPage::class, Hilos::PAGES[ModerationPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[ModerationPage::PAGE]);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION,
        ], ModerationPage::BROWSER);
        self::assertSame([
            ChatTableContext::hilosModerationKinds => [],
        ], Hilos::PAGE_TABLES[ModerationPage::PAGE]);
        self::assertSame([], ModerationPage::ACTIONS);
        self::assertSame([], ModerationPage::SIGNALS);

        self::assertSame(ModerationKindPage::class, Hilos::PAGES[ModerationKindPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[ModerationKindPage::PAGE]);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION_KIND,
        ], ModerationKindPage::BROWSER);
        self::assertSame([
            ChatTableContext::hilosModerationKinds => [],
        ], Hilos::PAGE_TABLES[ModerationKindPage::PAGE]);
        self::assertSame(PageAccessLevel::ADMIN, ModerationKindPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, ModerationKindPage::REACH);
        self::assertSame([], ModerationKindPage::ACTIONS);
        self::assertSame([], ModerationKindPage::SIGNALS);

        self::assertSame(ModerationKindRulesPage::class, Hilos::PAGES[ModerationKindRulesPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[ModerationKindRulesPage::PAGE]);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION_KIND_RULES,
        ], ModerationKindRulesPage::BROWSER);
        self::assertSame([
            ChatTableContext::hilosModerationKinds => [],
            ChatTableContext::hilosModerationRules => [],
        ], Hilos::PAGE_TABLES[ModerationKindRulesPage::PAGE]);
        self::assertSame(PageAccessLevel::ADMIN, ModerationKindRulesPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, ModerationKindRulesPage::REACH);
        self::assertSame([
            HilosSignalConstants::HILOS_MODERATION_RULE_ADD => HilosModerationRuleAddActionDTO::class,
            HilosSignalConstants::HILOS_MODERATION_RULE_DELETE => HilosModerationRuleDeleteActionDTO::class,
            HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE => HilosModerationRuleUpdateActionDTO::class,
        ], ModerationKindRulesPage::ACTIONS);
        self::assertSame([SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE => HandoverAnswerSignalData::class,
        ]], ModerationKindRulesPage::SIGNALS);
        self::assertSame([], ModerationKindRulesPage::READING_ACTIONS);
        self::assertSame(HilosModerationRuleAddActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::HILOS_MODERATION_RULE_ADD]);
        self::assertArrayNotHasKey(HilosSignalConstants::MODERATION_RULE_ADD, Hilos::getActionAgentRoutes());
        self::assertSame(HilosModerationRuleDeleteActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::HILOS_MODERATION_RULE_DELETE]);
        self::assertArrayNotHasKey(HilosSignalConstants::MODERATION_RULE_DELETE, Hilos::getActionAgentRoutes());
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_MODERATION_RULE_DELETE, ModerationRulesLibraryAgent::AGENT_ACTIONS);

        self::assertSame(HilosModerationRuleUpdateActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE]);
        self::assertArrayNotHasKey(HilosSignalConstants::MODERATION_RULE_UPDATE, Hilos::getActionAgentRoutes());
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE, ModerationRulesLibraryAgent::AGENT_ACTIONS);

        $entry = Hilos::AGENTS[HilosAgentType::HILOS_MODERATION_RULES_LIBRARY];
        $this->assertSame([
            AgentRegistryKey::WORKER => ModerationRulesLibraryAgent::class,
            AgentRegistryKey::DAEMON => ModerationRulesLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ], $entry);
        $this->assertSame(ModerationRulesLibraryAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(ModerationRulesLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
        $this->assertTrue((new ModerationRulesLibraryAgentDaemon())->requiresMonopolisticProcess());

        $moderator = Hilos::AGENTS[HilosAgentType::HILOS_MODERATOR];
        $this->assertSame([
            AgentRegistryKey::WORKER => ModerationAgent::class,
            AgentRegistryKey::DAEMON => ModerationAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ], $moderator);
        $this->assertSame(ModerationAgent::class, AgentRegistry::workerClass($moderator));
        $this->assertSame(ModerationAgentDaemon::class, AgentRegistry::daemonClass($moderator));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($moderator));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($moderator));
        $this->assertFalse(AgentRegistry::requiresIndex($moderator));
        $this->assertTrue((new ModerationAgentDaemon())->requiresMonopolisticProcess());

        $this->assertArrayHasKey(AgentType::MODERATOR, Hilos::AGENTS);
        $catalog = SettingsCatalog::getCatalog();
        foreach (ModerationSettingsCatalog::getCatalog() as $key => $settingEntry) {
            $this->assertSame($settingEntry, $catalog[$key]);
        }
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
        $this->assertSame(HilosI18nCountriesTable::class, Hilos::TABLES[ChatTableContext::hilosI18nCountries]);
        $this->assertSame([ChatTableContext::hilosI18nCountries => []], Hilos::PAGE_TABLES[CountriesListPage::PAGE]);
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
            HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET => HilosPageConstants::HILOS_APPEARANCE,
            HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET => HilosPageConstants::HILOS_APPEARANCE,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD => HilosPageConstants::HILOS_I18N_LANGUAGES,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_UPDATE => HilosPageConstants::HILOS_I18N_LANGUAGES,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_DELETE => HilosPageConstants::HILOS_I18N_LANGUAGES,
            HilosSignalConstants::HILOS_I18N_COUNTRY_ADD => HilosPageConstants::HILOS_I18N_COUNTRIES,
            HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_UPDATE => HilosPageConstants::HILOS_I18N_COUNTRIES,
            HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_DELETE => HilosPageConstants::HILOS_I18N_COUNTRIES,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF => HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON => HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_UPDATE => HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_DELETE => HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_ADD => HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_UPDATE => HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_DELETE => HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_OVERRIDE_DELETE => HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES,
            HilosSignalConstants::HILOS_I18N_LOCALE_ADD => HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
            HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE => HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
            HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON => HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
            HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF => HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
            HilosSignalConstants::HILOS_I18N_LOCALE_DELETE => HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
            HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON => HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF => HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_UPDATE => HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_DELETE => HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_ADD => HilosPageConstants::HILOS_I18N_COUNTRY_NAMES,
            HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_UPDATE => HilosPageConstants::HILOS_I18N_COUNTRY_NAMES,
            HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_DELETE => HilosPageConstants::HILOS_I18N_COUNTRY_NAMES,
            HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_OVERRIDE_DELETE => HilosPageConstants::HILOS_I18N_COUNTRY_NAMES,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_START => PageConstants::HILOS_GUARDIAN_AGENT,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => PageConstants::HILOS_GUARDIAN_AGENT,
            HilosSignalConstants::BACKUP_CREATE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_DELETE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_BULK_DELETE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_SET_KEEP => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_RESTORE => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_REOPEN => PageConstants::HILOS_BACKUP,
            HilosSignalConstants::MAINTENANCE_ENABLE => HilosPageConstants::HILOS_MAINTENANCE,
            HilosSignalConstants::MAINTENANCE_DISABLE => HilosPageConstants::HILOS_MAINTENANCE,
            HilosSignalConstants::MAINTENANCE_PASS => HilosPageConstants::HILOS_MAINTENANCE,
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS => HilosPageConstants::HILOS_MAINTENANCE,
            HilosSignalConstants::MAINTENANCE_RESTORE_OPEN => HilosPageConstants::HILOS_MAINTENANCE,
            HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE => HilosPageConstants::HILOS_MAINTENANCE,
            HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
            HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
            HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART => HilosPageConstants::HILOS_DAEMON_AGENTS,
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
            HilosSignalConstants::HILOS_MODERATION_RULE_ADD => HilosPageConstants::HILOS_MODERATION_KIND_RULES,
            HilosSignalConstants::HILOS_MODERATION_RULE_DELETE => HilosPageConstants::HILOS_MODERATION_KIND_RULES,
            HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE => HilosPageConstants::HILOS_MODERATION_KIND_RULES,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY => PageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
            HilosSignalConstants::SECURITY_2FA_SETTING_SET => HilosPageConstants::HILOS_SECURITY_2FA,
            HilosSignalConstants::SECURITY_2FA_SWITCH_SET => HilosPageConstants::HILOS_SECURITY_2FA,
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
            HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ => HilosPageConstants::HILOS_CHANGE_LOG_FEED,
            HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ => HilosPageConstants::HILOS_CHANGE_LOG_FEED,
            HilosSignalConstants::HILOS_TERMS_REVISION_TEXT => HilosPageConstants::HILOS_TERMS,
            HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT => HilosPageConstants::HILOS_PRIVACY,
            HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION => HilosPageConstants::HILOS_STATUS,
            HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW => HilosPageConstants::HILOS_STATUS,
            HilosSignalConstants::HILOS_STATUS_ADD_UPDATE => HilosPageConstants::HILOS_STATUS,
            HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE => HilosPageConstants::HILOS_STATUS_SUBSCRIBERS,
            HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE => HilosPageConstants::HILOS_STATUS_WORKS,
            HilosSignalConstants::HILOS_STATUS_WORK_CHANGE => HilosPageConstants::HILOS_STATUS_WORKS,
            HilosSignalConstants::HILOS_STATUS_WORK_CANCEL => HilosPageConstants::HILOS_STATUS_WORKS,
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
            HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_UPDATE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_ADD => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_UPDATE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_UPDATE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_ADD => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_UPDATE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_OVERRIDE_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LOCALE_ADD => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_LOCALE_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_UPDATE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_ADD => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_UPDATE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_OVERRIDE_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_START => AgentType::HILOS_GUARDIAN,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => AgentType::HILOS_GUARDIAN,
            HilosSignalConstants::BACKUP_CREATE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_DELETE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_BULK_DELETE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_SET_KEEP => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_RESTORE => AgentType::HILOS_INDEX,
            HilosSignalConstants::BACKUP_REOPEN => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_ENABLE => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_DISABLE => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_PASS => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_RESTORE_OPEN => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => AgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART => AgentType::HILOS_DAEMON,
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
            HilosSignalConstants::HILOS_MODERATION_RULE_ADD => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_MODERATION_RULE_DELETE => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST => AgentType::HILOS_INDEX,
            HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_2FA_SETTING_SET => AgentType::HILOS_INDEX,
            HilosSignalConstants::SECURITY_2FA_SWITCH_SET => AgentType::HILOS_INDEX,
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
            HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ => AgentType::HILOS_CHANGE_LOG,
            HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ => AgentType::HILOS_CHANGE_LOG,
            HilosSignalConstants::HILOS_TERMS_REVISION_TEXT => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_STATUS_ADD_UPDATE => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_STATUS_WORK_CHANGE => AgentType::HILOS_INDEX,
            HilosSignalConstants::HILOS_STATUS_WORK_CANCEL => AgentType::HILOS_INDEX,
        ], Hilos::getActionAgentRoutes());
    }

    public function testComputedPageSignalRoutesMatchChatSignalOwnership(): void
    {
        $this->assertSame([
            SignalTypeConstants::AGENT_SIGNAL => [
                ChatSignalConstants::MODERATION_RESULT => PageConstants::MAIN,
                ChatSignalConstants::ATTACHMENTS_PUBLISHED => PageConstants::MAIN,
                HilosSignalConstants::HILOS_SETTING_WRITE_DONE => HilosPageConstants::HILOS_SETTINGS,
                HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE => HilosPageConstants::HILOS_APPEARANCE,
                HilosSignalConstants::HILOS_BACKUP_DELETE_DONE => HilosPageConstants::HILOS_BACKUP,
                HilosSignalConstants::MAINTENANCE_ENABLE_DONE => HilosPageConstants::HILOS_MAINTENANCE,
                HilosSignalConstants::MAINTENANCE_DISABLE_DONE => HilosPageConstants::HILOS_MAINTENANCE,
                HilosSignalConstants::MAINTENANCE_PASS_DONE => HilosPageConstants::HILOS_MAINTENANCE,
                HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE => HilosPageConstants::HILOS_MAINTENANCE,
                HilosSignalConstants::DAEMON_AGENT_RESTART_DONE => HilosPageConstants::HILOS_DAEMON_AGENTS,
                HilosSignalConstants::HILOS_LOGS_SETTINGS_PRESET_APPLY_DONE
                    => HilosPageConstants::HILOS_LOGS_SETTINGS,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_IMPERSONATE_DONE => PageConstants::HILOS_USER,
                HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE => HilosPageConstants::HILOS_MODERATION_KIND_RULES,
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
                HilosSignalConstants::HILOS_STATUS_WRITE_DONE => HilosPageConstants::HILOS_STATUS,
                HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE => HilosPageConstants::HILOS_STATUS_SUBSCRIBERS,
                HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE => HilosPageConstants::HILOS_STATUS_WORKS,
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
                HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_BACKUP_DELETE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_ENABLE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_DISABLE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_PASS_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::DAEMON_AGENT_RESTART_DONE => AgentType::HILOS_DAEMON,
                HilosSignalConstants::HILOS_LOGS_SETTINGS_PRESET_APPLY_DONE => AgentType::HILOS_LOGS,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_IMPERSONATE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_SECOND_FACTOR_SETTING_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_STEP_UP_OPERATIONS_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_LEGAL_SETTING_WRITE_DONE => AgentType::HILOS_LEGAL,
                HilosSignalConstants::HILOS_OAUTH_REDIRECT_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_SIGN_IN_METHODS_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_STATUS_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE => AgentType::HILOS_INDEX,
                HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE => AgentType::HILOS_INDEX,
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
            HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE => HilosAgentType::HILOS_USER,
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
            HilosSignalConstants::HILOS_USER_STEP_UP_RECORD => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_PHOTO_PUT => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_PHOTO_REMOVE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_READ => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_USER_PUSH_REMOVE => HilosAgentType::HILOS_USER,
            HilosSignalConstants::HILOS_PROFILE_NAME_CONTINUE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_CODE_MINT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_RELEASE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_THEME_PICK_ADOPT_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_THEME_PICK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
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
            HilosSignalConstants::HILOS_USER_STEP_UP_RECORD_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => HilosAgentType::HILOS_USERS_LIBRARY,
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
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_EXPIRED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_MISSED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_SETUP_PROVEN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_DISABLE_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_IMPERSONATION_OFF_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_FLOW_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_NAME_ADVANCE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_NOTIFICATION_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_DELIVERY_RETRY => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_PUSH_SUBSCRIPTIONS_GONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
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
            HilosSignalConstants::HILOS_SETTING_COMMAND_DONE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosSignalConstants::MODERATION_RULE_ADD => HilosAgentType::HILOS_MODERATION_RULES_LIBRARY,
            HilosSignalConstants::MODERATION_RULE_DELETE => HilosAgentType::HILOS_MODERATION_RULES_LIBRARY,
            HilosSignalConstants::MODERATION_RULE_UPDATE => HilosAgentType::HILOS_MODERATION_RULES_LIBRARY,
            ChatSignalConstants::BOT_AGENT_START => AgentType::BOT,
            HilosSignalConstants::MAINTENANCE_AGENT_ENABLE => HilosAgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_AGENT_DISABLE => HilosAgentType::HILOS_INDEX,
            HilosSignalConstants::MAINTENANCE_AGENT_PASS => HilosAgentType::HILOS_INDEX,
            HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION => HilosAgentType::HILOS_LOGS,
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION => HilosAgentType::HILOS_DAEMON,
            HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET => HilosAgentType::HILOS_LEGAL,
            HilosSignalConstants::BACKUP_AGENT_CREATE => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_DELETE => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_SET_KEEP => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_RESTORE => AgentType::HILOS_BACKUP,
            HilosSignalConstants::BACKUP_AGENT_REOPEN => AgentType::HILOS_BACKUP,
            HilosSignalConstants::MAINTENANCE_AGENT_RESTORE_CONTROL => AgentType::HILOS_BACKUP,
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
            HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS => HilosAgentType::HILOS_DAEMON_NODE,
            HilosSignalConstants::DAEMON_AGENT_CRON => HilosAgentType::HILOS_DAEMON_NODE,
            HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT => HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosSignalConstants::DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_CANCEL_PUBLICATION => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_PUBLISH_NOW => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_ADD_UPDATE => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_SCHEDULE_WORK => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_CHANGE_WORK => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_CANCEL_WORK => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_THROTTLE_VERDICT => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => HilosAgentType::HILOS_ANALYTICS_WRITER,
            HilosSignalConstants::ANALYTICS_JOURNAL_READY => HilosAgentType::HILOS_ANALYTICS_WRITER,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => AgentType::HILOS_AUTH_THROTTLE,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => AgentType::HILOS_AUTH_THROTTLE,
            HilosSignalConstants::HILOS_AUTH_CODE_SEND => AgentType::HILOS_AUTH_CODE,
            HilosSignalConstants::HILOS_AUTH_CODE_MINTED => AgentType::HILOS_AUTH_CODE,
        ], Hilos::getAgentSignalRoutes());
    }

    public function testComputedCommandRoutesMatchChatAgentOwnership(): void
    {
        $this->assertSame([
            CliCommands::LEGAL_TEST_HOLD => HilosAgentType::HILOS_USERS_LIBRARY,
            CliCommands::VERIFICATION_TEST_SWEEP => HilosAgentType::HILOS_USERS_LIBRARY,
            CliCommands::VERIFICATION_TEST_END_PAUSE => HilosAgentType::HILOS_USERS_LIBRARY,
            CliCommands::SECOND_FACTOR_UNLOCK => HilosAgentType::HILOS_USERS_LIBRARY,
            CliCommands::USER_TEST_CREATE => HilosAgentType::HILOS_USERS_LIBRARY,
            CliCommands::ADMIN_CREATE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::ADMIN_GRANT => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::ADMIN_REVOKE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::IMPERSONATE_START => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::IMPERSONATE_STOP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::ACCOUNT_MERGE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::ACCOUNT_TEST_FORCE_PURGE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::SESSION_TEST_BIND => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            CliCommands::NOTIFICATION_TEST_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            CliCommands::SETTINGS_SET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
            CliCommands::SETTINGS_RESET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
            CliCommands::I18N_TEST_LANGUAGE_CREATE => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_COUNTRY_CREATE => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LOCALE_CREATE => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LANGUAGE_CLEAR => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_COUNTRY_CLEAR => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LOCALE_CLEAR => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LANGUAGE_ON => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LANGUAGE_OFF => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LANGUAGE_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LOCALE_DELETE => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LOCALE_ON => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_COUNTRY_OFF => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_COUNTRY_ON => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_COUNTRY_NAMES_SEED => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR => HilosAgentType::HILOS_I18N_LIBRARY,
            CliCommands::COMMAND_TEST_ECHO => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_ENTER => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_LEAVE => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_OPEN => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_PASS => AgentType::HILOS_INDEX,
            CliCommands::PROTECTED_MODE_TEST_CLOSE => AgentType::HILOS_INDEX,
            CliCommands::MAINTENANCE_ENABLE => AgentType::HILOS_INDEX,
            CliCommands::MAINTENANCE_DISABLE => AgentType::HILOS_INDEX,
            CliCommands::MAINTENANCE_PASS => AgentType::HILOS_INDEX,
            AnalyticsCommandConstants::TEST_BROWSER_SESSION => AgentType::HILOS_ANALYTICS,
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
            HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE => 'userId',
            HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE => 'userId',
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
            HilosSignalConstants::HILOS_USER_STEP_UP_RECORD => 'userId',
            HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT => 'userId',
            HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE => 'userId',
            HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL => 'userId',
            HilosSignalConstants::HILOS_USER_PHOTO_PUT => 'userId',
            HilosSignalConstants::HILOS_USER_PHOTO_REMOVE => 'userId',
            HilosSignalConstants::HILOS_USER_NOTIFICATION_READ => 'userId',
            HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET => 'userId',
            HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE => 'userId',
            HilosSignalConstants::HILOS_USER_PUSH_REMOVE => 'userId',
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
            HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE => UserTimeZoneWriteSignalData::class,
            HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE => UserLastBrowserTimeZoneWriteSignalData::class,
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
            HilosSignalConstants::HILOS_USER_STEP_UP_RECORD => UserStepUpRecordSignalData::class,
            HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT => UserStepUpCreditSignalData::class,
            HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE => UserBrowserTrustRevokeSignalData::class,
            HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL => UserAccountDeletionCancelSignalData::class,
            HilosSignalConstants::HILOS_USER_PHOTO_PUT => UserPhotoPutSignalData::class,
            HilosSignalConstants::HILOS_USER_PHOTO_REMOVE => UserPhotoRemoveSignalData::class,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_READ => UserNotificationReadSignalData::class,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET => UserNotificationChannelSetSignalData::class,
            HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE => UserPushUnsubscribeSignalData::class,
            HilosSignalConstants::HILOS_USER_PUSH_REMOVE => UserPushRemoveSignalData::class,
            HilosSignalConstants::HILOS_PROFILE_NAME_CONTINUE => ProfileNameContinueSignalData::class,
            HilosSignalConstants::HILOS_AUTH_CODE_MINT => AuthCodeMintSignalData::class,
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_RELEASE => AuthRegistrationHoldReleaseSignalData::class,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
            HilosSignalConstants::HILOS_USER_THEME_PICK_ADOPT_DONE => UserThemePickWriteDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_THEME_PICK_DONE => UserThemePickWriteDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE => UserTimeZoneWriteDoneSignalData::class,
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
            HilosSignalConstants::HILOS_USER_STEP_UP_RECORD_DONE => UserStepUpRecordDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE => UserAccountDeletionCancelDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE => UserPhotoPutDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE => UserPhotoRemoveDoneSignalData::class,
            HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => FilesPublishedSignalData::class,
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
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_EXPIRED => AuthRegistrationHoldExpiredSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_MISSED => AuthSecondFactorMissedSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_SETUP_PROVEN => AuthSecondFactorSetupProvenSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF => AuthSecondFactorOffSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY => AuthSecondFactorTrustDaysApplySignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_DISABLE_APPLY => AuthSecondFactorDisableApplySignalData::class,
            HilosSignalConstants::HILOS_AUTH_IMPERSONATION_OFF_APPLY => AuthImpersonationOffApplySignalData::class,
            HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END => AuthOtherSessionsEndSignalData::class,
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_CANCEL => AuthSecondFactorCancelSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED => AccountBlockChangedSignalData::class,
            HilosSignalConstants::HILOS_PROFILE_FLOW_STEP => ProfileFlowStepSignalData::class,
            HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN => ProfileNameBeginSignalData::class,
            HilosSignalConstants::HILOS_PROFILE_NAME_ADVANCE => ProfileNameAdvanceSignalData::class,
            HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE => UserSessionsRestateSignalData::class,
            HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE => UserAdminWriteDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE => UserAdminCommandDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE => UserBlockWriteDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT_DONE => UserStepUpCreditDoneSignalData::class,
            HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE => UserBrowserTrustRevokeDoneSignalData::class,
            HilosSignalConstants::HILOS_NOTIFICATION_EMIT => NotificationEmitSignalData::class,
            HilosSignalConstants::HILOS_DELIVERY_RETRY => DeliveryRetrySignalData::class,
            HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => DeferredNotificationHandoverSignalData::class,
            HilosSignalConstants::HILOS_PUSH_SUBSCRIPTIONS_GONE => PushSubscriptionsGoneSignalData::class,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE => HandoverAnswerSignalData::class,
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
            HilosSignalConstants::HILOS_SETTING_COMMAND_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::MODERATION_RULE_ADD => ModerationRuleAddSignalData::class,
            HilosSignalConstants::MODERATION_RULE_DELETE => ModerationRuleDeleteSignalData::class,
            HilosSignalConstants::MODERATION_RULE_UPDATE => ModerationRuleUpdateSignalData::class,
            ChatSignalConstants::BOT_AGENT_START => BotAgentSignalData::class,
            HilosSignalConstants::MAINTENANCE_AGENT_ENABLE => ManualMaintenanceEnableSignalData::class,
            HilosSignalConstants::MAINTENANCE_AGENT_DISABLE => ManualMaintenanceDisableSignalData::class,
            HilosSignalConstants::MAINTENANCE_AGENT_PASS => ManualMaintenancePassSignalData::class,
            HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION => ClusterLogIndexPortionSignalData::class,
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
            HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET => LegalAcceptancesExportForgetSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_CREATE => BackupCreateSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_DELETE => BackupDeleteSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_SET_KEEP => BackupSetKeepSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_RESTORE => BackupRestoreSignalData::class,
            HilosSignalConstants::BACKUP_AGENT_REOPEN => BackupReopenSignalData::class,
            HilosSignalConstants::MAINTENANCE_AGENT_RESTORE_CONTROL => MaintenanceRestoreControlSignalData::class,
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
            HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS => DaemonMasterMeasurementsSignalData::class,
            HilosSignalConstants::DAEMON_AGENT_CRON => DaemonAgentCronSignalData::class,
            HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT => DaemonNodePictureSignalData::class,
            HilosSignalConstants::DAEMON_PICTURE_WATCH => DaemonPictureWatchSignalData::class,
            HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH => DaemonPictureWatchSignalData::class,
            HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_PUBLICATION => StatusCancelPublicationSignalData::class,
            HilosSignalConstants::STATUS_PUBLISH_NOW => StatusPublishNowSignalData::class,
            HilosSignalConstants::STATUS_ADD_UPDATE => StatusAddUpdateSignalData::class,
            HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER => StatusRemoveSubscriberSignalData::class,
            HilosSignalConstants::STATUS_SCHEDULE_WORK => StatusScheduleWorkSignalData::class,
            HilosSignalConstants::STATUS_CHANGE_WORK => StatusChangeWorkSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_WORK => StatusCancelWorkSignalData::class,
            HilosSignalConstants::STATUS_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
            HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => AnalyticsJournalPortionSignalData::class,
            HilosSignalConstants::ANALYTICS_JOURNAL_READY => AnalyticsJournalReadySignalData::class,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => ThrottleCheckSignalData::class,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => ThrottleSuccessSignalData::class,
            HilosSignalConstants::HILOS_AUTH_CODE_SEND => AuthCodeSendSignalData::class,
            HilosSignalConstants::HILOS_AUTH_CODE_MINTED => AuthCodeMintedSignalData::class,
        ], $declaredRoutes);
        $this->assertSame($declaredRoutes, Hilos::getAgentSignalDtoRoutes());
    }

    public function testComputedAgentActionRoutesMatchChatAgentOwnership(): void
    {
        $this->assertSame([
            HilosSignalConstants::HILOS_DATA_EXPORT_ORDER => HilosAgentType::HILOS_DATA_EXPORT,
            HilosSignalConstants::HILOS_DETECT_IDENTIFIER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_THEME_ADOPT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_THEME_PICK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_TIME_ZONE_PICK => HilosAgentType::HILOS_USERS_LIBRARY,
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
            HilosSignalConstants::PROFILE_NAME_SET => HilosAgentType::HILOS_USERS_LIBRARY,
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
            HilosSignalConstants::STATUS_SUBSCRIBE => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_UNSUBSCRIBE => HilosAgentType::HILOS_STATUS,
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
            HilosSignalConstants::HILOS_THEME_ADOPT => ThemeAdoptActionDTO::class,
            HilosSignalConstants::HILOS_THEME_PICK => ThemePickActionDTO::class,
            HilosSignalConstants::HILOS_TIME_ZONE_PICK => TimeZonePickActionDTO::class,
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
            HilosSignalConstants::PROFILE_NAME_SET => ProfileNameSetActionDTO::class,
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
            HilosSignalConstants::STATUS_SUBSCRIBE => StatusSubscribeActionDTO::class,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => StatusConfirmSubscriptionActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => StatusUnsubscribePreviewActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE => StatusUnsubscribeActionDTO::class,
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

    public function testLogsOverviewBindsTheRegisteredNodeTable(): void
    {
        $this->assertSame(LogsOverviewPage::class, Hilos::PAGES[LogsOverviewPage::PAGE]);
        $this->assertSame(HilosLogNodesTable::class, Hilos::TABLES[ChatTableContext::hilosLogNodes]);
        $this->assertSame(
            [ChatTableContext::hilosLogNodes => []],
            Hilos::PAGE_TABLES[LogsOverviewPage::PAGE],
        );
    }

    public function testTimeZoneChoiceRequiresAuthentication(): void
    {
        $agentClass = AgentRegistry::workerClass(Hilos::AGENTS[HilosAgentType::HILOS_USERS_LIBRARY]);
        self::assertNotNull($agentClass);
        self::assertContains(HilosSignalConstants::HILOS_TIME_ZONE_PICK, $agentClass::AUTH_ACTIONS);
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
     * No database ownership receipts remain since HIL-1411; any new receipt fails this test.
     */
    public function testSharedOwnershipDebtDoesNotGrow(): void
    {
        $this->assertSame([], Hilos::SHARED_DB_OWNERS);
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

    public function testModerationKindDirectorySnapshot(): void
    {
        // The kinds the chat publishes to the moderator, pinned by hand: the framework's name and
        // photo first, untouched, then the chat's text and picture kinds. The chat takes profile
        // photos, so the photo kind is in the set. The old ModeratorAgent keeps its own prompts.
        $this->assertSame(ChatModerationKindDirectory::class, Hilos::moderationKindDirectoryClass());

        $kinds = Hilos::moderationKinds();

        $this->assertSame(
            [
                ModerationKindKey::PROFILE_NAME,
                ModerationKindKey::PROFILE_PHOTO,
                ChatModerationKindKey::MESSAGE,
                ChatModerationKindKey::MESSAGE_PICTURE,
            ],
            array_keys($kinds),
        );
        $this->assertSame(['chat_message', 'chat_message_picture'], [
            ChatModerationKindKey::MESSAGE,
            ChatModerationKindKey::MESSAGE_PICTURE,
        ]);

        $frameworkKinds = ModerationKindDirectory::all(true);
        $this->assertEquals($frameworkKinds[ModerationKindKey::PROFILE_NAME], $kinds[ModerationKindKey::PROFILE_NAME]);
        $this->assertEquals($frameworkKinds[ModerationKindKey::PROFILE_PHOTO], $kinds[ModerationKindKey::PROFILE_PHOTO]);

        $this->assertSame(
            [
                ModerationKindKey::PROFILE_NAME => [
                    'title' => 'Profile name',
                    'input' => 'text',
                    'reasons' => ['insult', 'threat', 'hate_speech', 'sexual', 'spam', 'impersonation'],
                    'defaultRules' => [
                        'Default policy: allow benign display names.',
                        'Block explicit insults, threats, hate speech, sexual content, spam, and impersonation.',
                        'If uncertain, return allow=true.',
                    ],
                    'verdictTo' => HilosAgentType::HILOS_USERS_LIBRARY,
                ],
                ModerationKindKey::PROFILE_PHOTO => [
                    'title' => 'Profile photo',
                    'input' => 'image',
                    'reasons' => ['nudity', 'violence', 'hate'],
                    'defaultRules' => [
                        'Default policy: allow ordinary photos, drawings and logos.',
                        'Block only nudity or sexual content, graphic violence, and hate symbols.',
                        'If uncertain, return allow=true.',
                    ],
                    'verdictTo' => HilosAgentType::HILOS_USERS_LIBRARY,
                ],
                ChatModerationKindKey::MESSAGE => [
                    'title' => 'Chat message',
                    'input' => 'text_with_files',
                    'reasons' => ['insult', 'threat', 'hate_speech', 'sexual', 'spam'],
                    'defaultRules' => [
                        'Default policy: allow benign messages.',
                        'Block only explicit insults, threats, hate speech, sexual content, and obvious spam.',
                        'If uncertain, return allow=true.',
                    ],
                    'verdictTo' => AgentType::CHAT,
                ],
                ChatModerationKindKey::MESSAGE_PICTURE => [
                    'title' => 'Chat message picture',
                    'input' => 'image',
                    'reasons' => ['nudity', 'violence', 'hate'],
                    'defaultRules' => [
                        'Default policy: allow ordinary pictures, screenshots and drawings.',
                        'Block only nudity or sexual content, graphic violence, and hate symbols.',
                        'If uncertain, return allow=true.',
                    ],
                    'verdictTo' => AgentType::CHAT,
                ],
            ],
            array_map(static fn (ModerationKind $kind): array => [
                'title' => $kind->title,
                'input' => $kind->input->value,
                'reasons' => $kind->reasons,
                'defaultRules' => $kind->defaultRules,
                'verdictTo' => $kind->verdictTo,
            ], $kinds),
        );
        $this->assertSame(ModerationInput::TEXT_WITH_FILES, $kinds[ChatModerationKindKey::MESSAGE]->input);
        $this->assertSame(ModerationInput::IMAGE, $kinds[ChatModerationKindKey::MESSAGE_PICTURE]->input);
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
        $databaseMetadata = [];
        foreach (['configurations', 'databaseBindings'] as $property) {
            $databaseMetadata[$property] = new ReflectionProperty(Database::class, $property)->getValue();
        }
        try {
            // The mounted journal chains need physical schema identities; configuration alone opens no connection.
            Database::configure(database: 'topology_unit');
            Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
            Hilos::$db = new ChatDbContext();
            Hilos::$db->configure();
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
            MountedCollectionKeyGuard::assertMountedKeysAgree();
        } finally {
            Hilos::$db = $previous;
            foreach ($databaseMetadata as $property => $value) {
                new ReflectionProperty(Database::class, $property)->setValue(null, $value);
            }
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
