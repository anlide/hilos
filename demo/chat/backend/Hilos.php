<?php

declare(strict_types=1);

namespace Demo\Chat;

use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosLegalAgentDaemon;
use Demo\Chat\Pages\Hilos\Legal\LegalPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentPage;
use Demo\Chat\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Chat\Pages\Hilos\Legal\LegalAcceptancesPage;
use Demo\Chat\Pages\Hilos\Legal\LegalSettingsPage;
use Demo\Chat\Tables\HilosLegal\HilosLegalAcceptancesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\ChatContextAnalyzerAgent;
use Demo\Chat\Agents\Hilos\DataExportAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Chat\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\Chat\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\Chat\Agents\Hilos\DemoHilosGuardianAgent;
use Demo\Chat\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Chat\Agents\Hilos\FilesLibraryAgent;
use Demo\Chat\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Agents\Hilos\UserAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Agents\OAuthAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Core\Agent\Daemon\BotAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ChatAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ChatContextAnalyzerAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosGuardianAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\FilesLibraryAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\LibraryAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ModeratorAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\Chat\Backup\BackupCatalog;
use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\Data\BotStatusBrowserData;
use Demo\Chat\Browser\Data\SelfConnectionBrowserData;
use Demo\Chat\Browser\Data\UserMergeBrowserData;
use Demo\Chat\Browser\Data\UserPresenceBrowserData;
use Demo\Chat\Browser\List\MainBotsBrowserList;
use Demo\Chat\Browser\List\MainEventsBrowserList;
use Demo\Chat\Browser\List\MainUsersBrowserList;
use Demo\Chat\Browser\List\ProfileDevicesBrowserList;
use Demo\Chat\Browser\List\ProfileSessionsBrowserList;
use Demo\Chat\Browser\Table\GuardianAgentStatusDetailBrowserTable;
use Demo\Chat\Browser\Table\GuardianAgentStatusesBrowserTable;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Pages\PageCatalog;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Environment\ChatEnvCatalog;
use Demo\Chat\Environment\ChatLlmProfileCatalog;
use Demo\Chat\Environment\ChatLlmProfileOverrideSource;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Fs\ChatFsContext;
use Demo\Chat\Groups\Hilos\NotificationsGroup;
use Demo\Chat\Groups\SessionGroup;
use Demo\Chat\Auth\ChatAuthMethodDirectory;
use Demo\Chat\Auth\ChatCodeChannelRegistry;
use Demo\Chat\Auth\ChatOAuthProviderDirectory;
use Demo\Chat\Auth\ChatStepUpOperationDirectory;
use Demo\Chat\Legal\LegalCatalog;
use Demo\Chat\Notification\ChatDeliveryChannelRegistry;
use Demo\Chat\Pages\AdminBotsPage;
use Demo\Chat\Pages\AdminPage;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Pages\Hilos\AboutPage;
use Demo\Chat\Pages\Hilos\AppearancePage;
use Demo\Chat\Pages\Hilos\AnalyticsPage;
use Demo\Chat\Pages\Hilos\Backup\BackupPage;
use Demo\Chat\Pages\Hilos\Billing\BillingPage;
use Demo\Chat\Pages\Hilos\Billing\BillingPaymentsPage;
use Demo\Chat\Pages\Hilos\Billing\BillingProviderPage;
use Demo\Chat\Pages\Hilos\Billing\BillingRefundsPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\Chat\Pages\Hilos\DashboardPage;
use Demo\Chat\Pages\Hilos\Guardian\GuardianAgentPage;
use Demo\Chat\Pages\Hilos\GuardianPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Chat\Pages\Hilos\I18nPage;
use Demo\Chat\Pages\Hilos\LicensePage;
use Demo\Chat\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Chat\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Chat\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\Chat\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\Chat\Pages\Hilos\Logs\LogsViewPage;
use Demo\Chat\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\Chat\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsDashboardPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpLogsPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpLogsViewPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpPage;
use Demo\Chat\Pages\Hilos\Operations\OperationsPage;
use Demo\Chat\Pages\Hilos\PrivacyPage;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Pages\Hilos\ProfileSignInPage;
use Demo\Chat\Pages\Hilos\ProfileNotificationsPage;
use Demo\Chat\Pages\Hilos\ProfileDevicesPage;
use Demo\Chat\Pages\Hilos\ProfileSessionsPage;
use Demo\Chat\Pages\Hilos\Roles\RolesPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Chat\Pages\Hilos\Security\SecurityImpersonationPage;
use Demo\Chat\Pages\Hilos\Security\SecuritySignInMethodsPage;
use Demo\Chat\Pages\Hilos\Security\SecurityPage;
use Demo\Chat\Pages\Hilos\ProfileSecurityPage;
use Demo\Chat\Pages\Hilos\ProfileDataPage;
use Demo\Chat\Pages\Hilos\ProfileAgreementsPage;
use Demo\Chat\Pages\Hilos\ProfileAgreementsHistoryPage;
use Demo\Chat\Pages\Hilos\Security\SecurityStepUpPage;
use Demo\Chat\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Demo\Chat\Pages\Hilos\Sil\SilDashboardPage;
use Demo\Chat\Pages\Hilos\Sil\SilRequestsPage;
use Demo\Chat\Pages\Hilos\Sil\SilUserHistoryPage;
use Demo\Chat\Pages\Hilos\TermsPage;
use Demo\Chat\Pages\Hilos\Users\UserPage as HilosUserPage;
use Demo\Chat\Pages\Hilos\Users\UsersPage as HilosUsersPage;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Pages\ModeratorPage;
use Demo\Chat\Pages\UserPage as ChatUserPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\Bot\BotsTable;
use Demo\Chat\Tables\ChatTableContext;
use Demo\Chat\Tables\HilosUser\HilosUsersTable;
use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\AuthCodeAgentDaemon;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\TruthSource\SharedOwnersKey;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseGuarantee;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Image\ImageFit;
use Hilos\Files\Image\ImageFormat;
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Image\ImagesAgentDaemon;
use Hilos\Files\Image\ImageVariant;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Files\Upload\UploadsAgentDaemon;
use Hilos\Fs\Context\FsContext;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use Hilos\Hilos as HilosFacade;
use Hilos\HilosException;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogAggregatorAgentDaemon;
use Hilos\Log\LogCarrierAgent;
use Hilos\Log\LogCarrierAgentDaemon;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\LogStoreAgentDaemon;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\Push\Delivery\PushDeliveryChannelAgent;
use Hilos\Push\Delivery\PushDeliveryChannelAgentDaemon;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgentDaemon;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Security\HilosSecurityStepUpTable;
use Hilos\Tables\Security\HilosSecurityImpersonationTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosUserPhotoBrowserTable;

/**
 * Hilos - Main app facade for data access.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL]->string()
 * - Hilos::$rt->connections
 * - Hilos::$rt->userStates
 * - Hilos::$table->users
 * - Hilos::$browser
 * - Hilos::$fs->files, Hilos::$fs->tmp
 *
 * @property-read ChatDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read ChatRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read ChatTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read ChatBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 * @property-read ChatFsContext $fs Filesystem context (narrows parent's FsContext for IDE)
 */
final class Hilos extends HilosFacade
{
    /**
     * @var array<string, list<TruthSourceOperation>> The users collection, named here because
     *     only the project knows its name. The claim is laid by the runner of a test-only
     *     command ({@see TestOnlyCommand}), which is the only thing that writes this table
     *     with no agent behind it.
     */
    public const array OWNS_DB = [ChatDbContext::users => TruthSourceOperation::BY_KIND];

    protected const string ENV_CATALOG = ChatEnvCatalog::class;

    protected const string SETTINGS_CATALOG = SettingsCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string LLM_PROFILE_CATALOG = ChatLlmProfileCatalog::class;

    protected const ?string LLM_PROFILE_OVERRIDE = ChatLlmProfileOverrideSource::class;

    protected const ?string BACKUP_CATALOG = BackupCatalog::class;

    protected const ?string LEGAL_CATALOG = LegalCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = ChatDeliveryChannelRegistry::class;

    protected const string CODE_CHANNEL_REGISTRY = ChatCodeChannelRegistry::class;

    protected const string AUTH_METHOD_DIRECTORY = ChatAuthMethodDirectory::class;

    protected const string STEP_UP_OPERATION_DIRECTORY = ChatStepUpOperationDirectory::class;

    protected const string OAUTH_PROVIDER_DIRECTORY = ChatOAuthProviderDirectory::class;

    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::I18N,
        HilosFeature::HILOS_USERS,
        HilosFeature::BACKUP,
        HilosFeature::LOGS,
        HilosFeature::DAEMON,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::CODE_CHANNELS,
        HilosFeature::FILES,
        HilosFeature::UPLOADS,
        HilosFeature::IMAGES,
        HilosFeature::PROFILE_PHOTO,
        HilosFeature::ANALYTICS,
    ];

    public const ?string PROFILE_PHOTO_CHECKER = AgentType::MODERATOR;

    /** A file attached to a message - the chat's one kind of upload (HIL-144). */
    public const array UPLOAD_TARGETS = [
        ChatAttachmentUploadTarget::NAME => ChatAttachmentUploadTarget::class,
    ];

    /** The picture the feed shows in place of an attached image; a click opens the original. */
    public const array IMAGE_VARIANTS = [
        ChatAttachmentUploadTarget::THUMB_VARIANT => [
            ImageVariant::WIDTH => 384,
            ImageVariant::HEIGHT => 384,
            ImageVariant::FIT => ImageFit::CONTAIN,
            ImageVariant::FORMAT => ImageFormat::WEBP,
        ],
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        ProfilePage::PAGE => ProfilePage::class,
        ProfileSignInPage::PAGE => ProfileSignInPage::class,
        ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
        ProfileSessionsPage::PAGE => ProfileSessionsPage::class,
        ProfileDevicesPage::PAGE => ProfileDevicesPage::class,
        ChatUserPage::PAGE => ChatUserPage::class,
        BotPage::PAGE => BotPage::class,
        ModeratorPage::PAGE => ModeratorPage::class,
        AdminPage::PAGE => AdminPage::class,
        AdminBotsPage::PAGE => AdminBotsPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        AppearancePage::PAGE => AppearancePage::class,
        I18nPage::PAGE => I18nPage::class,
        LanguagesListPage::PAGE => LanguagesListPage::class,
        CountriesListPage::PAGE => CountriesListPage::class,
        LanguageDetailPage::PAGE => LanguageDetailPage::class,
        LanguageNamesPage::PAGE => LanguageNamesPage::class,
        LanguageLocalesPage::PAGE => LanguageLocalesPage::class,
        CountryDetailPage::PAGE => CountryDetailPage::class,
        CountryNamesPage::PAGE => CountryNamesPage::class,
        GuardianPage::PAGE => GuardianPage::class,
        GuardianAgentPage::PAGE => GuardianAgentPage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        BackupPage::PAGE => BackupPage::class,
        MaintenancePage::PAGE => MaintenancePage::class,
        DaemonPage::PAGE => DaemonPage::class,
        DaemonWorkersPage::PAGE => DaemonWorkersPage::class,
        DaemonAgentsPage::PAGE => DaemonAgentsPage::class,
        DaemonCronPage::PAGE => DaemonCronPage::class,
        DaemonWebsocketsPage::PAGE => DaemonWebsocketsPage::class,
        DaemonHttpServerPage::PAGE => DaemonHttpServerPage::class,
        DaemonEnvPage::PAGE => DaemonEnvPage::class,
        DaemonEnvMismatchPage::PAGE => DaemonEnvMismatchPage::class,
        LogsOverviewPage::PAGE => LogsOverviewPage::class,
        LogsKeysPage::PAGE => LogsKeysPage::class,
        LogsWorkersPage::PAGE => LogsWorkersPage::class,
        LogsRotationsPage::PAGE => LogsRotationsPage::class,
        LogsViewPage::PAGE => LogsViewPage::class,
        LogsSettingsPage::PAGE => LogsSettingsPage::class,
        OperationsPage::PAGE => OperationsPage::class,
        HilosUsersPage::PAGE => HilosUsersPage::class,
        HilosUserPage::PAGE => HilosUserPage::class,
        RolesPage::PAGE => RolesPage::class,
        McpSkillsDashboardPage::PAGE => McpSkillsDashboardPage::class,
        McpSkillsMcpPage::PAGE => McpSkillsMcpPage::class,
        McpSkillsMcpLogsPage::PAGE => McpSkillsMcpLogsPage::class,
        McpSkillsMcpLogsViewPage::PAGE => McpSkillsMcpLogsViewPage::class,
        SilDashboardPage::PAGE => SilDashboardPage::class,
        SilRequestsPage::PAGE => SilRequestsPage::class,
        SilUserHistoryPage::PAGE => SilUserHistoryPage::class,
        CommunicationsPage::PAGE => CommunicationsPage::class,
        CommunicationsChannelPage::PAGE => CommunicationsChannelPage::class,
        CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
        SecurityPage::PAGE => SecurityPage::class,
        SecurityTwoFactorPage::PAGE => SecurityTwoFactorPage::class,
        SecurityStepUpPage::PAGE => SecurityStepUpPage::class,
        LegalPage::PAGE => LegalPage::class,
        LegalDocumentPage::PAGE => LegalDocumentPage::class,
        LegalRevisionPage::PAGE => LegalRevisionPage::class,
        LegalAcceptancesPage::PAGE => LegalAcceptancesPage::class,
        LegalSettingsPage::PAGE => LegalSettingsPage::class,
        ProfileSecurityPage::PAGE => ProfileSecurityPage::class,
        ProfileAgreementsPage::PAGE => ProfileAgreementsPage::class,
        ProfileAgreementsHistoryPage::PAGE => ProfileAgreementsHistoryPage::class,
        ProfileDataPage::PAGE => ProfileDataPage::class,
        SecurityOAuthPage::PAGE => SecurityOAuthPage::class,
        SecurityOAuthProviderPage::PAGE => SecurityOAuthProviderPage::class,
        SecuritySignInMethodsPage::PAGE => SecuritySignInMethodsPage::class,
        SecurityImpersonationPage::PAGE => SecurityImpersonationPage::class,
        BillingPage::PAGE => BillingPage::class,
        BillingProviderPage::PAGE => BillingProviderPage::class,
        BillingPaymentsPage::PAGE => BillingPaymentsPage::class,
        BillingRefundsPage::PAGE => BillingRefundsPage::class,
        ChangeLogDashboardPage::PAGE => ChangeLogDashboardPage::class,
        ChangeLogTablesPage::PAGE => ChangeLogTablesPage::class,
        ChangeLogTablePage::PAGE => ChangeLogTablePage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicensePage::PAGE => LicensePage::class,
    ];

    public const array GROUPS = [
        SessionGroup::GROUP => SessionGroup::class,
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        DataExportAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DataExportAgent::class,
            AgentRegistryKey::DAEMON => DataExportAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        ChatAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ChatAgent::class,
            AgentRegistryKey::DAEMON => ChatAgentDaemon::class,
        ],
        UserAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => UserAgent::class,
            AgentRegistryKey::DAEMON => UserAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
            AgentRegistryKey::IDLE_TIMEOUT => AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC,
        ],
        UsersLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => UsersLibraryAgent::class,
            AgentRegistryKey::DAEMON => UsersLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        SessionsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SessionsLibraryAgent::class,
            AgentRegistryKey::DAEMON => SessionsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        NotificationsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => NotificationsLibraryAgent::class,
            AgentRegistryKey::DAEMON => NotificationsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        FilesLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => FilesLibraryAgent::class,
            AgentRegistryKey::DAEMON => FilesLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        UploadsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => UploadsAgent::class,
            AgentRegistryKey::DAEMON => UploadsAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        ImagesAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ImagesAgent::class,
            AgentRegistryKey::DAEMON => ImagesAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        SettingsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SettingsLibraryAgent::class,
            AgentRegistryKey::DAEMON => SettingsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        I18nLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => I18nLibraryAgent::class,
            AgentRegistryKey::DAEMON => I18nLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        LibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LibraryAgent::class,
            AgentRegistryKey::DAEMON => LibraryAgentDaemon::class,
        ],
        ChatContextAnalyzerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ChatContextAnalyzerAgent::class,
            AgentRegistryKey::DAEMON => ChatContextAnalyzerAgentDaemon::class,
        ],
        BotAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BotAgent::class,
            AgentRegistryKey::DAEMON => BotAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
        ],
        ModeratorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ModeratorAgent::class,
            AgentRegistryKey::DAEMON => ModeratorAgentDaemon::class,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
        ],
        DemoHilosGuardianAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosGuardianAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosGuardianAgentDaemon::class,
        ],
        DemoHilosAnalyticsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAnalyticsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAnalyticsAgentDaemon::class,
        ],
        DemoHilosChangeLogAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosChangeLogAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosChangeLogAgentDaemon::class,
        ],
        DemoHilosLogsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosLogsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosLogsAgentDaemon::class,
        ],
        DemoHilosDaemonAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosDaemonAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosDaemonAgentDaemon::class,
        ],
        DemoHilosLegalAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosLegalAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosLegalAgentDaemon::class,
        ],
        BackupAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BackupAgent::class,
            AgentRegistryKey::DAEMON => BackupAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        OAuthAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => OAuthAgent::class,
            AgentRegistryKey::DAEMON => OAuthAgentDaemon::class,
        ],
        MailDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => MailDeliveryChannelAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        SmsDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SmsDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => SmsDeliveryChannelAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        PushDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => PushDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => PushDeliveryChannelAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        LogStoreAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogStoreAgent::class,
            AgentRegistryKey::DAEMON => LogStoreAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        AnalyticsJournalAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AnalyticsJournalAgent::class,
            AgentRegistryKey::DAEMON => AnalyticsJournalAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        LogCarrierAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogCarrierAgent::class,
            AgentRegistryKey::DAEMON => LogCarrierAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        LogAggregatorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogAggregatorAgent::class,
            AgentRegistryKey::DAEMON => LogAggregatorAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        DaemonNodeAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DaemonNodeAgent::class,
            AgentRegistryKey::DAEMON => DaemonNodeAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        DaemonCollectorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DaemonCollectorAgent::class,
            AgentRegistryKey::DAEMON => DaemonCollectorAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        AnalyticsWriterAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AnalyticsWriterAgent::class,
            AgentRegistryKey::DAEMON => AnalyticsWriterAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        AuthThrottleAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AuthThrottleAgent::class,
            AgentRegistryKey::DAEMON => AuthThrottleAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        AuthCodeAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AuthCodeAgent::class,
            AgentRegistryKey::DAEMON => AuthCodeAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
    ];

    /**
     * The collections this demo still lets two owners hold, and who will part them.
     *
     * Receipts, not permissions: every pair here is a place where two agents write the same rows
     * today, and startup refuses both a pair that is missing from this list and a row whose
     * owners no longer collide. The rights of nobody changed when the list was written - what
     * writes today goes on writing, out loud instead of by eye.
     *
     * The four rows are the framework's auth libraries and the code agent sharing the tables of
     * signing in, and they stand the same way in every demo that switches those features on. The
     * person is no longer among them: an edit of one person is that person's agent's, and the
     * libraries' shares of the row no longer collide (HIL-1404).
     *
     * The rows are parted by their own leaves: identities by HIL-1405, stepUps by HIL-1407, and
     * verifications and registrationReservations by HIL-1411.
     * See docs/agents/architecture/instance-owners.md#where-the-pieces-land.
     */
    public const array SHARED_DB_OWNERS = [
        HilosDbContext::stepUps => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, SessionsLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1407',
        ],
        HilosDbContext::identities => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, OAuthAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1405',
        ],
        HilosDbContext::verifications => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, AuthCodeAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1411',
        ],
        HilosDbContext::registrationReservations => [
            SharedOwnersKey::OWNERS => [
                UsersLibraryAgent::class,
                SessionsLibraryAgent::class,
                AuthCodeAgent::class,
            ],
            SharedOwnersKey::DEBT => 'HIL-1411',
        ],
    ];

    public const array TABLES = [
        ChatTableContext::hilosUsers => HilosUsersTable::class,
        ChatTableContext::hilosMergeCandidates => HilosMergeCandidatesTable::class,
        ChatTableContext::bots => BotsTable::class,
        ChatTableContext::settings => HilosSettingsTable::class,
        ChatTableContext::hilosAppearanceSettings => HilosAppearanceSettingsTable::class,
        ChatTableContext::hilosBackups => HilosBackupHistoryTable::class,
        ChatTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
        ChatTableContext::hilosCommunicationsChannels => HilosCommunicationsChannelsTable::class,
        ChatTableContext::hilosCommunicationsChannelFields => HilosCommunicationsChannelFieldsTable::class,
        ChatTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
        ChatTableContext::hilosLogKeys => HilosLogKeysTable::class,
        ChatTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        ChatTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        ChatTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
        ChatTableContext::hilosDaemonWorkers => HilosDaemonWorkersTable::class,
        ChatTableContext::hilosDaemonAgents => HilosDaemonAgentsTable::class,
        ChatTableContext::hilosSecurityOauthProviders => HilosSecurityOAuthProvidersTable::class,
        ChatTableContext::hilosSecurityOauthProviderFields => HilosSecurityOAuthProviderFieldsTable::class,
        ChatTableContext::hilosSecurityOauthRedirect => HilosSecurityOAuthRedirectTable::class,
        ChatTableContext::hilosSecuritySignInMethods => HilosSecuritySignInMethodsTable::class,
        ChatTableContext::hilosSecurityTwoFactor => HilosSecurityTwoFactorTable::class,
        ChatTableContext::hilosSecurityImpersonation => HilosSecurityImpersonationTable::class,
        ChatTableContext::hilosLegalDocuments => HilosLegalDocumentsTable::class,
        ChatTableContext::hilosLegalChecks => HilosLegalChecksTable::class,
        ChatTableContext::hilosLegalRevisions => HilosLegalRevisionsTable::class,
        ChatTableContext::hilosLegalAcceptances => HilosLegalAcceptancesTable::class,
        ChatTableContext::hilosLegalSettings => HilosLegalSettingsTable::class,
        ChatTableContext::hilosSecurityStepUp => HilosSecurityStepUpTable::class,
        ChatTableContext::hilosI18nLanguageNames => HilosI18nLanguageNamesTable::class,
        ChatTableContext::hilosI18nCountryNames => HilosI18nCountryNamesTable::class,
        ChatTableContext::hilosI18nLanguageLocales => HilosI18nLanguageLocalesTable::class,
    ];

    public const array BROWSER_LISTS = [
        MainEventsBrowserList::LIST => MainEventsBrowserList::class,
        MainUsersBrowserList::LIST => MainUsersBrowserList::class,
        MainBotsBrowserList::LIST => MainBotsBrowserList::class,
        HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::class,
        ProfileSessionsBrowserList::LIST => ProfileSessionsBrowserList::class,
        ProfileDevicesBrowserList::LIST => ProfileDevicesBrowserList::class,
    ];

    public const array BROWSER_DATA = [
        SelfConnectionBrowserData::DATA => SelfConnectionBrowserData::class,
        BotStatusBrowserData::DATA => BotStatusBrowserData::class,
        UserPresenceBrowserData::DATA => UserPresenceBrowserData::class,
        UserMergeBrowserData::DATA => UserMergeBrowserData::class,
        LanguageCardBrowserData::DATA => LanguageCardBrowserData::class,
        CountryCardBrowserData::DATA => CountryCardBrowserData::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
        HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::class,
        GuardianAgentStatusesBrowserTable::TABLE => GuardianAgentStatusesBrowserTable::class,
        GuardianAgentStatusDetailBrowserTable::TABLE => GuardianAgentStatusDetailBrowserTable::class,
    ];

    public const array PAGE_LISTS = [
        MainPage::PAGE => [
            MainEventsBrowserList::LIST => [],
            MainUsersBrowserList::LIST => [],
            MainBotsBrowserList::LIST => [],
        ],
        ProfilePage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
            ProfileSessionsBrowserList::LIST => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
            ProfileDevicesBrowserList::LIST => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ProfileSignInPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileSessionsPage::PAGE => [
            ProfileSessionsBrowserList::LIST => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ProfileDevicesPage::PAGE => [
            ProfileDevicesBrowserList::LIST => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
    ];

    public const array PAGE_DATA = [
        MainPage::PAGE => [
            SelfConnectionBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ProfilePage::PAGE => [
            SelfConnectionBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ProfileSessionsPage::PAGE => [
            SelfConnectionBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ChatUserPage::PAGE => [
            UserPresenceBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    UserPageSubscribeParams::USER_ID => ChatBrowserRef::USER_ID,
                ],
            ],
            UserMergeBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    UserPageSubscribeParams::USER_ID => ChatBrowserRef::USER_ID,
                ],
            ],
        ],
        BotPage::PAGE => [
            BotStatusBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    BotPageSubscribeParams::BOT_ID => ChatBrowserRef::BOT_ID,
                ],
            ],
        ],
        LanguageDetailPage::PAGE => [
            LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING,
        ],
        LanguageLocalesPage::PAGE => [
            LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING,
        ],
        CountryDetailPage::PAGE => [
            CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING,
        ],
    ];

    public const array PAGE_TABLES = [
        AdminBotsPage::PAGE => [
            ChatTableContext::bots => [],
        ],
        SettingsPage::PAGE => [
            ChatTableContext::settings => [],
        ],
        AppearancePage::PAGE => [
            ChatTableContext::hilosAppearanceSettings => [],
        ],
        BackupPage::PAGE => [
            ChatTableContext::hilosBackups => [],
        ],
        MaintenancePage::PAGE => [
            ChatTableContext::hilosVerifierCircle => [],
        ],
        CommunicationsPage::PAGE => [
            ChatTableContext::hilosCommunicationsChannels => [],
        ],
        CommunicationsChannelPage::PAGE => [
            ChatTableContext::hilosCommunicationsChannelFields => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            ChatTableContext::hilosNotificationDeliveries => [],
        ],
        SecurityOAuthPage::PAGE => [
            ChatTableContext::hilosSecurityOauthRedirect => [],
            ChatTableContext::hilosSecurityOauthProviders => [],
        ],
        SecurityOAuthProviderPage::PAGE => [
            ChatTableContext::hilosSecurityOauthProviders => [],
            ChatTableContext::hilosSecurityOauthProviderFields => [],
        ],
        SecuritySignInMethodsPage::PAGE => [
            ChatTableContext::hilosSecuritySignInMethods => [],
        ],
        SecurityTwoFactorPage::PAGE => [
            ChatTableContext::hilosSecurityTwoFactor => [],
        ],
        SecurityStepUpPage::PAGE => [
            ChatTableContext::hilosSecurityStepUp => [],
        ],
        SecurityImpersonationPage::PAGE => [
            ChatTableContext::hilosSecurityImpersonation => [],
        ],
        LegalPage::PAGE => [
            ChatTableContext::hilosLegalDocuments => [],
            ChatTableContext::hilosLegalChecks => [],
            ChatTableContext::hilosLegalSettings => [],
        ],
        LegalDocumentPage::PAGE => [
            ChatTableContext::hilosLegalRevisions => [],
        ],
        LegalRevisionPage::PAGE => [
            ChatTableContext::hilosLegalRevisions => [],
        ],
        LegalAcceptancesPage::PAGE => [
            ChatTableContext::hilosLegalAcceptances => [],
        ],
        LegalSettingsPage::PAGE => [
            ChatTableContext::hilosLegalSettings => [],
        ],
        LogsKeysPage::PAGE => [
            ChatTableContext::hilosLogKeys => [],
        ],
        LogsRotationsPage::PAGE => [
            ChatTableContext::hilosLogRotations => [],
        ],
        LogsWorkersPage::PAGE => [
            ChatTableContext::hilosLogWorkers => [],
        ],
        DaemonCronPage::PAGE => [
            ChatTableContext::hilosDaemonCron => [],
        ],
        DaemonWorkersPage::PAGE => [
            ChatTableContext::hilosDaemonWorkers => [],
        ],
        DaemonAgentsPage::PAGE => [
            ChatTableContext::hilosDaemonAgents => [],
        ],
        GuardianPage::PAGE => [
            GuardianAgentStatusesBrowserTable::TABLE => [],
        ],
        GuardianAgentPage::PAGE => [
            GuardianAgentStatusDetailBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID => ChatBrowserRef::HILOS_GUARDIAN_AGENT_ID,
                ],
            ],
        ],
        HilosUsersPage::PAGE => [
            ChatTableContext::hilosUsers => [],
        ],
        HilosUserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
            ChatTableContext::hilosMergeCandidates => [],
        ],
        LanguageNamesPage::PAGE => [
            ChatTableContext::hilosI18nLanguageNames => [],
        ],
        LanguageLocalesPage::PAGE => [
            ChatTableContext::hilosI18nLanguageLocales => [],
        ],
        CountryNamesPage::PAGE => [
            ChatTableContext::hilosI18nCountryNames => [],
        ],
    ];

    /**
     * Creates a fixture user in the chat users collection and returns its id.
     *
     * Project side of the {@see HilosFacade::createFixtureUser()} seam (test-only user
     * seeding): the framework does not know the project's users collection, so this creates
     * the row through the existing name-only create path. Owning that collection while the
     * row is written is not this method's work any more - the class declares it in
     * {@see self::OWNS_DB} and the runner of the command lays the claim down.
     *
     * @param string $displayName Display name for the seeded user
     * @return ?int Created user id
     * @throws HilosException When the user write fails, including a blank display name
     */
    public static function createFixtureUser(string $displayName): ?int
    {
        return (int)Hilos::$db->users->actions->createWithName($displayName)->id;
    }

    /**
     * Creates the chat database context.
     *
     * @return ChatDbContext Chat database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new ChatDbContext();
    }

    /**
     * Creates the chat runtime context.
     *
     * @return ?ChatRtContext Chat runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new ChatRtContext();
    }

    /**
     * Creates the chat table context.
     *
     * @return ?ChatTableContext Chat table context
     */
    protected static function createTable(): ?TableContext
    {
        return new ChatTableContext();
    }

    /**
     * Creates the chat browser-facing context.
     *
     * @return ?ChatBrowserContext Chat browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new ChatBrowserContext();
    }

    /**
     * Creates the chat filesystem context.
     *
     * @return ?ChatFsContext Chat filesystem context
     */
    protected static function createFs(): ?FsContext
    {
        return new ChatFsContext();
    }

}
