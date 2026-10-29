<?php

declare(strict_types=1);

namespace Demo\Tasks;

use Demo\Tasks\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosLegalAgentDaemon;
use Demo\Tasks\Pages\Hilos\Legal\LegalPage;
use Demo\Tasks\Pages\Hilos\Legal\LegalDocumentPage;
use Demo\Tasks\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Tasks\Pages\Hilos\Legal\LegalAcceptancesPage;
use Demo\Tasks\Pages\Hilos\Legal\LegalSettingsPage;
use Demo\Tasks\Tables\HilosLegal\HilosLegalAcceptancesTable;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;

use Demo\Tasks\Agents\Hilos\DataExportAgent;
use Demo\Tasks\Agents\Hilos\DemoHilosAgent;
use Demo\Tasks\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Tasks\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\Tasks\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Tasks\Agents\Hilos\FilesLibraryAgent;
use Demo\Tasks\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Tasks\Agents\Hilos\SessionsLibraryAgent;
use Demo\Tasks\Agents\Hilos\UserAgent;
use Demo\Tasks\Agents\Hilos\UsersLibraryAgent;
use Demo\Tasks\Agents\OAuthAgent;
use Demo\Tasks\Agents\TasksAgent;
use Demo\Tasks\Auth\TasksAuthMethodDirectory;
use Demo\Tasks\Auth\TasksCodeChannelRegistry;
use Demo\Tasks\Auth\TasksOAuthProviderDirectory;
use Demo\Tasks\Backup\BackupCatalog;
use Demo\Tasks\Browser\TasksBrowserContext;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\FilesLibraryAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\TasksAgentDaemon;
use Demo\Tasks\Database\Pages\PageCatalog;
use Demo\Tasks\Database\Settings\TasksSettingsCatalog;
use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Environment\TasksEnvCatalog;
use Demo\Tasks\Fs\TasksFsContext;
use Demo\Tasks\Legal\TasksLegalCatalog;
use Demo\Tasks\Notification\TasksDeliveryChannelRegistry;
use Demo\Tasks\Pages\Hilos\AboutPage;
use Demo\Tasks\Pages\Hilos\AppearancePage;
use Demo\Tasks\Pages\Hilos\Backup\BackupPage;
use Demo\Tasks\Pages\Hilos\Communications\CommunicationsChannelConfigurationPage;
use Demo\Tasks\Pages\Hilos\Communications\CommunicationsChannelFramePage;
use Demo\Tasks\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\Tasks\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\Tasks\Pages\Hilos\Communications\CommunicationsPage;
use Demo\Tasks\Pages\Hilos\DashboardPage;
use Demo\Tasks\Pages\Hilos\AnalyticsPage;
use Demo\Tasks\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\Tasks\Pages\Hilos\Analytics\PersonPage;
use Demo\Tasks\Pages\Hilos\LicensePage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsViewPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\Tasks\Pages\Hilos\Maintenance\MaintenanceCirclePage;
use Demo\Tasks\Pages\Hilos\Maintenance\MaintenanceNodesPage;
use Demo\Tasks\Pages\Hilos\Maintenance\MaintenanceWordsPage;
use Demo\Tasks\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\Tasks\Pages\Hilos\Status\StatusPage;
use Demo\Tasks\Pages\Hilos\Status\StatusComponentsPage;
use Demo\Tasks\Pages\Hilos\Status\StatusSubscribersPage;
use Demo\Tasks\Pages\Hilos\Status\StatusWorksPage;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Tables\Status\HilosStatusSubscribersTable;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Demo\Tasks\Pages\Hilos\PrivacyPage;
use Demo\Tasks\Pages\Hilos\PrivacyHistoryPage;
use Demo\Tasks\Pages\Hilos\SettingsPage;
use Demo\Tasks\Pages\Hilos\I18nPage;
use Demo\Tasks\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\Tasks\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Tasks\Pages\Hilos\TermsPage;
use Demo\Tasks\Pages\Hilos\TermsHistoryPage;
use Demo\Tasks\Groups\Hilos\NotificationsGroup;
use Demo\Tasks\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityImpersonationPage;
use Demo\Tasks\Pages\Hilos\Security\SecuritySignInMethodsPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityPage;
use Demo\Tasks\Pages\Hilos\ProfilePage;
use Demo\Tasks\Pages\Hilos\ProfileSignInPage;
use Demo\Tasks\Pages\Hilos\ProfileSecurityPage;
use Demo\Tasks\Pages\Hilos\ProfileDataPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityStepUpPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Tasks\Pages\Hilos\Users\UserPage;
use Demo\Tasks\Pages\Hilos\Users\UsersPage;
use Demo\Tasks\Pages\MainPage;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
use Demo\Tasks\Tables\HilosUser\HilosUsersTable;
use Demo\Tasks\Tables\TasksTableContext;
use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\AuthCodeAgentDaemon;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Table\Context\TableContext;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseGuarantee;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Image\ImagesAgentDaemon;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Files\Upload\UploadsAgentDaemon;
use Hilos\Fs\Context\FsContext;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use Hilos\Hilos as HilosFacade;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogAggregatorAgentDaemon;
use Hilos\Log\LogCarrierAgent;
use Hilos\Log\LogCarrierAgentDaemon;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\LogStoreAgentDaemon;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgentDaemon;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogNodesTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Security\HilosSecurityStepUpTable;
use Hilos\Tables\Security\HilosSecurityImpersonationTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosUserPhotoBrowserTable;
use Demo\Tasks\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\Tasks\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\Tasks\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\Tasks\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\Tasks\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\Tasks\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\Tasks\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\Tasks\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\Tasks\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Core\Agent\Daemon\StatusAgentDaemon;
use Hilos\Status\StatusAgent;

/**
 * Hilos - Main app facade for data access.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->settings
 * - Hilos::$setting->catalog()
 * - Hilos::$rt->connections
 * - Hilos::$table->settings
 *
 * @property-read TasksDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read TasksRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read TasksTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read TasksBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = TasksEnvCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string SETTINGS_CATALOG = TasksSettingsCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = TasksDeliveryChannelRegistry::class;

    protected const string CODE_CHANNEL_REGISTRY = TasksCodeChannelRegistry::class;

    protected const string AUTH_METHOD_DIRECTORY = TasksAuthMethodDirectory::class;

    protected const string OAUTH_PROVIDER_DIRECTORY = TasksOAuthProviderDirectory::class;

    protected const ?string BACKUP_CATALOG = BackupCatalog::class;

    protected const ?string LEGAL_CATALOG = TasksLegalCatalog::class;

    protected const array FEATURES = [
        HilosFeature::CHANGE_LOG,
        HilosFeature::ANALYTICS,
        HilosFeature::SETTINGS,
        HilosFeature::I18N,
        HilosFeature::HILOS_USERS,
        HilosFeature::LOGS,
        HilosFeature::DAEMON,
        HilosFeature::STATUS,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::CODE_CHANNELS,
        HilosFeature::BACKUP,
        HilosFeature::FILES,
        HilosFeature::UPLOADS,
        HilosFeature::IMAGES,
        HilosFeature::PROFILE_PHOTO,
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    public const array PAGES = [
        ChangeLogDashboardPage::PAGE => ChangeLogDashboardPage::class,
        ChangeLogFeedPage::PAGE => ChangeLogFeedPage::class,
        ChangeLogNoisyPage::PAGE => ChangeLogNoisyPage::class,
        ChangeLogTablesPage::PAGE => ChangeLogTablesPage::class,
        ChangeLogTablePage::PAGE => ChangeLogTablePage::class,
        ChangeLogTableColumnsPage::PAGE => ChangeLogTableColumnsPage::class,
        ChangeLogTableTriggersPage::PAGE => ChangeLogTableTriggersPage::class,
        ChangeLogTableHistoryPage::PAGE => ChangeLogTableHistoryPage::class,
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        BrowserSessionPage::PAGE => BrowserSessionPage::class,
        PersonPage::PAGE => PersonPage::class,
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
        BackupPage::PAGE => BackupPage::class,
        MaintenancePage::PAGE => MaintenancePage::class,
        MaintenanceCirclePage::PAGE => MaintenanceCirclePage::class,
        MaintenanceNodesPage::PAGE => MaintenanceNodesPage::class,
        MaintenanceWordsPage::PAGE => MaintenanceWordsPage::class,
        DaemonPage::PAGE => DaemonPage::class,
        DaemonPlacementPage::PAGE => DaemonPlacementPage::class,
        DaemonNodePage::PAGE => DaemonNodePage::class,
        DaemonWorkersPage::PAGE => DaemonWorkersPage::class,
        DaemonAgentsPage::PAGE => DaemonAgentsPage::class,
        DaemonCronPage::PAGE => DaemonCronPage::class,
        DaemonWebsocketsPage::PAGE => DaemonWebsocketsPage::class,
        DaemonHttpServerPage::PAGE => DaemonHttpServerPage::class,
        DaemonEnvPage::PAGE => DaemonEnvPage::class,
        DaemonEnvOrphansPage::PAGE => DaemonEnvOrphansPage::class,
        DaemonEnvMismatchPage::PAGE => DaemonEnvMismatchPage::class,
        LogsOverviewPage::PAGE => LogsOverviewPage::class,
        LogsKeysPage::PAGE => LogsKeysPage::class,
        LogsWorkersPage::PAGE => LogsWorkersPage::class,
        LogsRotationsPage::PAGE => LogsRotationsPage::class,
        LogsViewPage::PAGE => LogsViewPage::class,
        LogsSettingsPage::PAGE => LogsSettingsPage::class,
        CommunicationsPage::PAGE => CommunicationsPage::class,
        CommunicationsChannelPage::PAGE => CommunicationsChannelPage::class,
        CommunicationsChannelConfigurationPage::PAGE => CommunicationsChannelConfigurationPage::class,
        CommunicationsChannelFramePage::PAGE => CommunicationsChannelFramePage::class,
        CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        TermsHistoryPage::PAGE => TermsHistoryPage::class,
        PrivacyHistoryPage::PAGE => PrivacyHistoryPage::class,
        LicensePage::PAGE => LicensePage::class,
        SecurityPage::PAGE => SecurityPage::class,
        SecurityTwoFactorPage::PAGE => SecurityTwoFactorPage::class,
        SecurityStepUpPage::PAGE => SecurityStepUpPage::class,
        LegalPage::PAGE => LegalPage::class,
        LegalDocumentPage::PAGE => LegalDocumentPage::class,
        LegalRevisionPage::PAGE => LegalRevisionPage::class,
        LegalAcceptancesPage::PAGE => LegalAcceptancesPage::class,
        LegalSettingsPage::PAGE => LegalSettingsPage::class,
        ProfilePage::PAGE => ProfilePage::class,
        ProfileSignInPage::PAGE => ProfileSignInPage::class,
        ProfileSecurityPage::PAGE => ProfileSecurityPage::class,
        ProfileDataPage::PAGE => ProfileDataPage::class,
        SecurityOAuthPage::PAGE => SecurityOAuthPage::class,
        SecurityOAuthProviderPage::PAGE => SecurityOAuthProviderPage::class,
        SecuritySignInMethodsPage::PAGE => SecuritySignInMethodsPage::class,
        SecurityImpersonationPage::PAGE => SecurityImpersonationPage::class,
        StatusPage::PAGE => StatusPage::class,
        StatusComponentsPage::PAGE => StatusComponentsPage::class,
        StatusSubscribersPage::PAGE => StatusSubscribersPage::class,
        StatusWorksPage::PAGE => StatusWorksPage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
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
        DemoHilosChangeLogAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosChangeLogAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosChangeLogAgentDaemon::class,
        ],
        DataExportAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DataExportAgent::class,
            AgentRegistryKey::DAEMON => DataExportAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        TasksAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TasksAgent::class,
            AgentRegistryKey::DAEMON => TasksAgentDaemon::class,
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
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
        ],
        DemoHilosAnalyticsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAnalyticsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAnalyticsAgentDaemon::class,
        ],
        AnalyticsJournalAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AnalyticsJournalAgent::class,
            AgentRegistryKey::DAEMON => AnalyticsJournalAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        AnalyticsWriterAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AnalyticsWriterAgent::class,
            AgentRegistryKey::DAEMON => AnalyticsWriterAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        DemoHilosDaemonAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosDaemonAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosDaemonAgentDaemon::class,
        ],
        DemoHilosLogsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosLogsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosLogsAgentDaemon::class,
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
        LogStoreAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogStoreAgent::class,
            AgentRegistryKey::DAEMON => LogStoreAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        LogCarrierAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogCarrierAgent::class,
            AgentRegistryKey::DAEMON => LogCarrierAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
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
        StatusAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => StatusAgent::class,
            AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::CLUSTER,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        LogAggregatorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogAggregatorAgent::class,
            AgentRegistryKey::DAEMON => LogAggregatorAgentDaemon::class,
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

    public const array TABLES = [
        TasksTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
        TasksTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
        TasksTableContext::hilosChangeLogFeed => HilosChangeLogFeedTable::class,
        TasksTableContext::hilosChangeLogHistory => HilosChangeLogHistoryTable::class,
        TasksTableContext::hilosChangeLogTableColumns => HilosChangeLogTableColumnsTable::class,
        TasksTableContext::hilosChangeLogTableTriggers => HilosChangeLogTableTriggersTable::class,
        TasksTableContext::hilosChangeLogNoisyColumns => HilosChangeLogNoisyColumnsTable::class,
        TasksTableContext::hilosChangeLogTables => HilosChangeLogTablesTable::class,
        TasksTableContext::settings => HilosSettingsTable::class,
        TasksTableContext::hilosAppearanceSettings => HilosAppearanceSettingsTable::class,
        TasksTableContext::hilosUsers => HilosUsersTable::class,
        TasksTableContext::hilosBackups => HilosBackupHistoryTable::class,
        TasksTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
        TasksTableContext::hilosMaintenanceNodes => HilosMaintenanceNodesTable::class,
        TasksTableContext::hilosMaintenanceWords => HilosMaintenanceWordsTable::class,
        TasksTableContext::hilosLogKeys => HilosLogKeysTable::class,
        TasksTableContext::hilosLogNodes => HilosLogNodesTable::class,
        TasksTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        TasksTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        TasksTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
        TasksTableContext::hilosDaemonWorkers => HilosDaemonWorkersTable::class,
        TasksTableContext::hilosDaemonEnvKeys => HilosDaemonEnvKeysTable::class,
        TasksTableContext::hilosDaemonEnvOrphans => HilosDaemonEnvOrphansTable::class,
        TasksTableContext::hilosDaemonAgents => HilosDaemonAgentsTable::class,
        TasksTableContext::hilosDaemonEnvMismatch => HilosDaemonEnvMismatchTable::class,
        TasksTableContext::hilosDaemonHttpRoutes => HilosDaemonHttpRoutesTable::class,
        TasksTableContext::hilosDaemonPlacement => HilosDaemonPlacementTable::class,
        TasksTableContext::hilosCommunicationsChannels => HilosCommunicationsChannelsTable::class,
        TasksTableContext::hilosCommunicationsChannelFields => HilosCommunicationsChannelFieldsTable::class,
        TasksTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
        TasksTableContext::hilosSecurityOauthProviders => HilosSecurityOAuthProvidersTable::class,
        TasksTableContext::hilosSecurityOauthProviderFields => HilosSecurityOAuthProviderFieldsTable::class,
        TasksTableContext::hilosSecurityOauthRedirect => HilosSecurityOAuthRedirectTable::class,
        TasksTableContext::hilosSecuritySignInMethods => HilosSecuritySignInMethodsTable::class,
        TasksTableContext::hilosSecurityTwoFactor => HilosSecurityTwoFactorTable::class,
        TasksTableContext::hilosSecurityImpersonation => HilosSecurityImpersonationTable::class,
        TasksTableContext::hilosLegalDocuments => HilosLegalDocumentsTable::class,
        TasksTableContext::hilosLegalChecks => HilosLegalChecksTable::class,
        TasksTableContext::hilosLegalRevisions => HilosLegalRevisionsTable::class,
        TasksTableContext::hilosLegalAcceptances => HilosLegalAcceptancesTable::class,
        TasksTableContext::hilosLegalSettings => HilosLegalSettingsTable::class,
        TasksTableContext::hilosSecurityStepUp => HilosSecurityStepUpTable::class,
        TasksTableContext::hilosI18nLanguageNames => HilosI18nLanguageNamesTable::class,
        TasksTableContext::hilosI18nCountryNames => HilosI18nCountryNamesTable::class,
        TasksTableContext::hilosI18nLanguageLocales => HilosI18nLanguageLocalesTable::class,
        TasksTableContext::hilosI18nLanguages => HilosI18nLanguagesTable::class,
        TasksTableContext::hilosI18nCountries => HilosI18nCountriesTable::class,
        TasksTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
        TasksTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
        HilosStatusComponentsTable::TABLE => HilosStatusComponentsTable::class,
        HilosStatusSubscribersTable::TABLE => HilosStatusSubscribersTable::class,
        HilosStatusWorksTable::TABLE => HilosStatusWorksTable::class,
    ];

    public const array BROWSER_LISTS = [
        HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::class,
    ];

    public const array BROWSER_DATA = [
        LanguageCardBrowserData::DATA => LanguageCardBrowserData::class,
        CountryCardBrowserData::DATA => CountryCardBrowserData::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
        HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::class,
    ];

    public const array PAGE_LISTS = [
        ProfilePage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileSignInPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
    ];

    public const array PAGE_DATA = [
        LanguageDetailPage::PAGE => [
            LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING,
        ],
        LanguageNamesPage::PAGE => [
            LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING,
        ],
        LanguageLocalesPage::PAGE => [
            LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING,
        ],
        CountryDetailPage::PAGE => [
            CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING,
        ],
        CountryNamesPage::PAGE => [
            CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING,
        ],
    ];

    public const array PAGE_TABLES = [
        TermsHistoryPage::PAGE => [
            TasksTableContext::hilosTermsHistory => [],
        ],
        PrivacyHistoryPage::PAGE => [
            TasksTableContext::hilosPrivacyHistory => [],
        ],
        BrowserSessionPage::PAGE => [
            TasksTableContext::hilosAnalyticsActions => [],
        ],
        PersonPage::PAGE => [
            TasksTableContext::hilosAnalyticsBrowserSessions => [],
        ],
        ChangeLogFeedPage::PAGE => [
            TasksTableContext::hilosChangeLogFeed => [],
        ],
        ChangeLogTablesPage::PAGE => [
            TasksTableContext::hilosChangeLogTables => [],
        ],
        ChangeLogNoisyPage::PAGE => [
            TasksTableContext::hilosChangeLogNoisyColumns => [],
        ],
        ChangeLogTableHistoryPage::PAGE => [
            TasksTableContext::hilosChangeLogHistory => [],
        ],
        ChangeLogTableColumnsPage::PAGE => [
            TasksTableContext::hilosChangeLogTableColumns => [],
        ],
        ChangeLogTableTriggersPage::PAGE => [
            TasksTableContext::hilosChangeLogTableTriggers => [],
        ],
        SettingsPage::PAGE => [
            TasksTableContext::settings => [],
        ],
        AppearancePage::PAGE => [
            TasksTableContext::hilosAppearanceSettings => [],
        ],
        BackupPage::PAGE => [
            TasksTableContext::hilosBackups => [],
        ],
        MaintenancePage::PAGE => [
            TasksTableContext::hilosVerifierCircle => [],
        ],
        MaintenanceCirclePage::PAGE => [
            TasksTableContext::hilosVerifierCircle => [],
        ],
        MaintenanceWordsPage::PAGE => [
            TasksTableContext::hilosMaintenanceWords => [],
        ],
        MaintenanceNodesPage::PAGE => [
            TasksTableContext::hilosMaintenanceNodes => [],
        ],
        LogsOverviewPage::PAGE => [
            TasksTableContext::hilosLogNodes => [],
        ],
        LogsKeysPage::PAGE => [
            TasksTableContext::hilosLogKeys => [],
        ],
        LogsRotationsPage::PAGE => [
            TasksTableContext::hilosLogRotations => [],
        ],
        LogsWorkersPage::PAGE => [
            TasksTableContext::hilosLogWorkers => [],
        ],
        DaemonCronPage::PAGE => [
            TasksTableContext::hilosDaemonCron => [],
        ],
        DaemonWorkersPage::PAGE => [
            TasksTableContext::hilosDaemonWorkers => [],
        ],
        DaemonEnvPage::PAGE => [
            TasksTableContext::hilosDaemonEnvKeys => [],
        ],
        DaemonEnvOrphansPage::PAGE => [
            TasksTableContext::hilosDaemonEnvOrphans => [],
        ],
        DaemonPlacementPage::PAGE => [
            TasksTableContext::hilosDaemonPlacement => [],
        ],
        DaemonAgentsPage::PAGE => [
            TasksTableContext::hilosDaemonAgents => [],
        ],
        DaemonEnvMismatchPage::PAGE => [
            TasksTableContext::hilosDaemonEnvMismatch => [],
        ],
        DaemonHttpServerPage::PAGE => [
            TasksTableContext::hilosDaemonHttpRoutes => [],
        ],
        CommunicationsPage::PAGE => [
            TasksTableContext::hilosCommunicationsChannels => [],
        ],
        CommunicationsChannelConfigurationPage::PAGE => [
            TasksTableContext::hilosCommunicationsChannelFields => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            TasksTableContext::hilosNotificationDeliveries => [],
        ],
        SecurityOAuthPage::PAGE => [
            TasksTableContext::hilosSecurityOauthRedirect => [],
            TasksTableContext::hilosSecurityOauthProviders => [],
        ],
        SecurityOAuthProviderPage::PAGE => [
            TasksTableContext::hilosSecurityOauthProviders => [],
            TasksTableContext::hilosSecurityOauthProviderFields => [],
        ],
        SecuritySignInMethodsPage::PAGE => [
            TasksTableContext::hilosSecuritySignInMethods => [],
        ],
        SecurityTwoFactorPage::PAGE => [
            TasksTableContext::hilosSecurityTwoFactor => [],
        ],
        SecurityStepUpPage::PAGE => [
            TasksTableContext::hilosSecurityStepUp => [],
        ],
        SecurityImpersonationPage::PAGE => [
            TasksTableContext::hilosSecurityImpersonation => [],
        ],
        LegalPage::PAGE => [
            TasksTableContext::hilosLegalDocuments => [],
            TasksTableContext::hilosLegalChecks => [],
            TasksTableContext::hilosLegalSettings => [],
        ],
        LegalDocumentPage::PAGE => [
            TasksTableContext::hilosLegalRevisions => [],
        ],
        LegalRevisionPage::PAGE => [
            TasksTableContext::hilosLegalRevisions => [],
        ],
        LegalAcceptancesPage::PAGE => [
            TasksTableContext::hilosLegalAcceptances => [],
        ],
        LegalSettingsPage::PAGE => [
            TasksTableContext::hilosLegalSettings => [],
        ],
        UsersPage::PAGE => [
            TasksTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
        ],
        LanguagesListPage::PAGE => [
            TasksTableContext::hilosI18nLanguages => [],
        ],
        CountriesListPage::PAGE => [
            TasksTableContext::hilosI18nCountries => [],
        ],
        LanguageNamesPage::PAGE => [
            TasksTableContext::hilosI18nLanguageNames => [],
        ],
        LanguageLocalesPage::PAGE => [
            TasksTableContext::hilosI18nLanguageLocales => [],
        ],
        CountryNamesPage::PAGE => [
            TasksTableContext::hilosI18nCountryNames => [],
        ],
        StatusComponentsPage::PAGE => [HilosStatusComponentsTable::TABLE => []],
        StatusSubscribersPage::PAGE => [HilosStatusSubscribersTable::TABLE => []],
        StatusWorksPage::PAGE => [HilosStatusWorksTable::TABLE => []],
    ];

    /**
     * Creates the tasks database context.
     *
     * @return TasksDbContext Tasks database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new TasksDbContext();
    }

    /**
     * Creates the tasks runtime context.
     *
     * @return ?TasksRtContext Tasks runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new TasksRtContext();
    }

    /**
     * Creates the tasks table context.
     *
     * @return ?TasksTableContext Tasks table context
     */
    protected static function createTable(): ?TableContext
    {
        return new TasksTableContext();
    }

    /**
     * Creates the tasks browser-facing context.
     *
     * @return ?TasksBrowserContext Tasks browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new TasksBrowserContext();
    }

    /**
     * @return ?TasksFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new TasksFsContext();
    }
}
