<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker;

use Demo\BinanceBtcTracker\Agents\BinanceBtcTrackerAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DataExportAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DemoHilosAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DemoHilosLogsAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\NotificationsLibraryAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\SessionsLibraryAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\UserAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\UsersLibraryAgent;
use Demo\BinanceBtcTracker\Auth\BinanceBtcTrackerAuthMethodDirectory;
use Demo\BinanceBtcTracker\Backup\BackupCatalog;
use Demo\BinanceBtcTracker\Browser\BinanceBtcTrackerBrowserContext;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\BinanceBtcTrackerAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\BinanceBtcTracker\Database\BinanceBtcTrackerDbContext;
use Demo\BinanceBtcTracker\Database\Pages\PageCatalog;
use Demo\BinanceBtcTracker\Database\Settings\BinanceBtcTrackerSettingsCatalog;
use Demo\BinanceBtcTracker\Environment\BinanceBtcTrackerEnvCatalog;
use Demo\BinanceBtcTracker\Fs\BinanceBtcTrackerFsContext;
use Demo\BinanceBtcTracker\Groups\Hilos\NotificationsGroup;
use Demo\BinanceBtcTracker\Legal\BinanceBtcTrackerLegalCatalog;
use Demo\BinanceBtcTracker\Notification\BinanceBtcTrackerDeliveryChannelRegistry;
use Demo\BinanceBtcTracker\Pages\Hilos\AboutPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Backup\BackupPage;
use Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Communications\CommunicationsChannelConfigurationPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Communications\CommunicationsChannelFramePage;
use Demo\BinanceBtcTracker\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Communications\CommunicationsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\DashboardPage;
use Demo\BinanceBtcTracker\Pages\Hilos\AnalyticsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Analytics\PersonPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Analytics\PeoplePage;
use Demo\BinanceBtcTracker\Pages\Hilos\LicensePage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsKeysPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsViewPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Maintenance\MaintenanceCirclePage;
use Demo\BinanceBtcTracker\Pages\Hilos\Maintenance\MaintenanceNodesPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Maintenance\MaintenanceWordsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\BinanceBtcTracker\Pages\Hilos\Status\StatusPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Status\StatusComponentsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Status\StatusSubscribersPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Status\StatusWorksPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Status\StatusHistoryPage;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Status\HilosStatusSubscribersTable;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Hilos\Tables\Status\HilosStatusHistoryTable;
use Demo\BinanceBtcTracker\Pages\Hilos\PrivacyPage;
use Demo\BinanceBtcTracker\Pages\Hilos\PrivacyHistoryPage;
use Demo\BinanceBtcTracker\Pages\Hilos\ProfileNotificationsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\SettingsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\AppearancePage;
use Demo\BinanceBtcTracker\Pages\Hilos\I18nPage;
use Demo\BinanceBtcTracker\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\BinanceBtcTracker\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\BinanceBtcTracker\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\BinanceBtcTracker\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\BinanceBtcTracker\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\BinanceBtcTracker\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\BinanceBtcTracker\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\BinanceBtcTracker\Pages\Hilos\TermsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\TermsHistoryPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Users\UserPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Users\UsersPage;
use Demo\BinanceBtcTracker\Pages\MainPage;
use Demo\BinanceBtcTracker\Runtime\View\Context\BinanceBtcTrackerRtContext;
use Demo\BinanceBtcTracker\Tables\BinanceBtcTrackerTableContext;
use Demo\BinanceBtcTracker\Tables\HilosUser\HilosUsersTable;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseGuarantee;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
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
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgentDaemon;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
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
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Core\Agent\Daemon\StatusAgentDaemon;
use Hilos\Status\StatusAgent;

/**
 * Hilos - Main app facade for data access.
 *
 * The smallest complete shape of a project: sign-in by password, an empty home, the admin
 * dashboard and the four public footer pages. Six admin sections are activated so far -
 * Maintenance, the verifier circle a freeze lets through, Backup, the database archives,
 * Settings, which carries the example keys and the keys the log and delivery sections ask for,
 * Users, the people and a person's card (renaming, takeover, rights, block, scheduled
 * deletion; no account merge - this demo wires none of its seams), Logs, the live tail and the
 * rotated batches, and Communications, the channel hub, a channel's page and its delivery
 * journal - and the others arrive one by one, each with the leaf that moves its e2e onto this
 * demo.
 *
 * Notifications are switched on with them: the bell in the header, a person's own channel
 * switches on /profile/notifications (the one profile page this demo has, without the profile
 * root) and delivery by email and SMS.
 *
 * Its cluster stand (docker/docker-compose.cluster.yml) runs the framework's fleet and
 * runtime-set probes.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read BinanceBtcTrackerDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read BinanceBtcTrackerRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read BinanceBtcTrackerTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read BinanceBtcTrackerBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
class Hilos extends HilosFacade
{
    /**
     * @var array<string, list<TruthSourceOperation>> The users collection, named here because
     *     only the project knows its name. The claim is laid by the runner of a test-only
     *     command ({@see TestOnlyCommand}), which is the only thing that writes this table
     *     with no agent behind it.
     */
    public const array OWNS_DB = [BinanceBtcTrackerDbContext::users => TruthSourceOperation::BY_KIND];

    protected const string ENV_CATALOG = BinanceBtcTrackerEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = BinanceBtcTrackerAuthMethodDirectory::class;

    protected const ?string LEGAL_CATALOG = BinanceBtcTrackerLegalCatalog::class;

    protected const ?string BACKUP_CATALOG = BackupCatalog::class;

    protected const string SETTINGS_CATALOG = BinanceBtcTrackerSettingsCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = BinanceBtcTrackerDeliveryChannelRegistry::class;

    protected const array FEATURES = [
        HilosFeature::ANALYTICS,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::BACKUP,
        HilosFeature::CHANGE_LOG,
        HilosFeature::SETTINGS,
        HilosFeature::I18N,
        HilosFeature::HILOS_USERS,
        HilosFeature::LOGS,
        HilosFeature::DAEMON,
        HilosFeature::STATUS,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        BrowserSessionsPage::PAGE => BrowserSessionsPage::class,
        PeoplePage::PAGE => PeoplePage::class,
        BrowserSessionPage::PAGE => BrowserSessionPage::class,
        PersonPage::PAGE => PersonPage::class,
        BackupPage::PAGE => BackupPage::class,
        ChangeLogDashboardPage::PAGE => ChangeLogDashboardPage::class,
        ChangeLogFeedPage::PAGE => ChangeLogFeedPage::class,
        ChangeLogNoisyPage::PAGE => ChangeLogNoisyPage::class,
        ChangeLogTablesPage::PAGE => ChangeLogTablesPage::class,
        ChangeLogTablePage::PAGE => ChangeLogTablePage::class,
        ChangeLogTableColumnsPage::PAGE => ChangeLogTableColumnsPage::class,
        ChangeLogTableTriggersPage::PAGE => ChangeLogTableTriggersPage::class,
        ChangeLogTableHistoryPage::PAGE => ChangeLogTableHistoryPage::class,
        MaintenancePage::PAGE => MaintenancePage::class,
        MaintenanceCirclePage::PAGE => MaintenanceCirclePage::class,
        MaintenanceNodesPage::PAGE => MaintenanceNodesPage::class,
        MaintenanceWordsPage::PAGE => MaintenanceWordsPage::class,
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
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
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
        ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        TermsHistoryPage::PAGE => TermsHistoryPage::class,
        PrivacyHistoryPage::PAGE => PrivacyHistoryPage::class,
        LicensePage::PAGE => LicensePage::class,
        StatusPage::PAGE => StatusPage::class,
        StatusComponentsPage::PAGE => StatusComponentsPage::class,
        StatusSubscribersPage::PAGE => StatusSubscribersPage::class,
        StatusWorksPage::PAGE => StatusWorksPage::class,
        StatusHistoryPage::PAGE => StatusHistoryPage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        BinanceBtcTrackerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BinanceBtcTrackerAgent::class,
            AgentRegistryKey::DAEMON => BinanceBtcTrackerAgentDaemon::class,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
        ],
        DemoHilosAnalyticsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAnalyticsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAnalyticsAgentDaemon::class,
        ],
        DemoHilosChangeLogAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosChangeLogAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosChangeLogAgentDaemon::class,
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
        DataExportAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DataExportAgent::class,
            AgentRegistryKey::DAEMON => DataExportAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        SessionsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SessionsLibraryAgent::class,
            AgentRegistryKey::DAEMON => SessionsLibraryAgentDaemon::class,
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
        AuthThrottleAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AuthThrottleAgent::class,
            AgentRegistryKey::DAEMON => AuthThrottleAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        BackupAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BackupAgent::class,
            AgentRegistryKey::DAEMON => BackupAgentDaemon::class,
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
        // The probes of this demo's cluster stand - scenarios 5 and 13 read the fleet, scenario
        // 20 the set probe. A probe starts only on a clustered node of a non-production
        // environment, so this demo on one node, on its Playwright stand and in production
        // carries the rows and runs none of them (docs/agents/testing.md, "The cluster stands").
        HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
        HilosAgentType::HILOS_PROBE_RT_SET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_RT_SET],
    ];

    public const array TABLES = [
        BinanceBtcTrackerTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
        BinanceBtcTrackerTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
        BinanceBtcTrackerTableContext::hilosChangeLogFeed => HilosChangeLogFeedTable::class,
        BinanceBtcTrackerTableContext::hilosChangeLogHistory => HilosChangeLogHistoryTable::class,
        BinanceBtcTrackerTableContext::hilosChangeLogTableColumns => HilosChangeLogTableColumnsTable::class,
        BinanceBtcTrackerTableContext::hilosChangeLogTableTriggers => HilosChangeLogTableTriggersTable::class,
        BinanceBtcTrackerTableContext::hilosChangeLogNoisyColumns => HilosChangeLogNoisyColumnsTable::class,
        BinanceBtcTrackerTableContext::hilosChangeLogTables => HilosChangeLogTablesTable::class,
        BinanceBtcTrackerTableContext::hilosBackups => HilosBackupHistoryTable::class,
        BinanceBtcTrackerTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
        BinanceBtcTrackerTableContext::hilosMaintenanceNodes => HilosMaintenanceNodesTable::class,
        BinanceBtcTrackerTableContext::hilosMaintenanceWords => HilosMaintenanceWordsTable::class,
        BinanceBtcTrackerTableContext::settings => HilosSettingsTable::class,
        BinanceBtcTrackerTableContext::hilosAppearanceSettings => HilosAppearanceSettingsTable::class,
        BinanceBtcTrackerTableContext::hilosUsers => HilosUsersTable::class,
        BinanceBtcTrackerTableContext::hilosLogKeys => HilosLogKeysTable::class,
        BinanceBtcTrackerTableContext::hilosLogNodes => HilosLogNodesTable::class,
        BinanceBtcTrackerTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        BinanceBtcTrackerTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        BinanceBtcTrackerTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
        BinanceBtcTrackerTableContext::hilosDaemonWorkers => HilosDaemonWorkersTable::class,
        BinanceBtcTrackerTableContext::hilosDaemonEnvKeys => HilosDaemonEnvKeysTable::class,
        BinanceBtcTrackerTableContext::hilosDaemonEnvOrphans => HilosDaemonEnvOrphansTable::class,
        BinanceBtcTrackerTableContext::hilosDaemonAgents => HilosDaemonAgentsTable::class,
        BinanceBtcTrackerTableContext::hilosDaemonEnvMismatch => HilosDaemonEnvMismatchTable::class,
        BinanceBtcTrackerTableContext::hilosDaemonHttpRoutes => HilosDaemonHttpRoutesTable::class,
        BinanceBtcTrackerTableContext::hilosDaemonPlacement => HilosDaemonPlacementTable::class,
        BinanceBtcTrackerTableContext::hilosCommunicationsChannels => HilosCommunicationsChannelsTable::class,
        BinanceBtcTrackerTableContext::hilosCommunicationsChannelFields => HilosCommunicationsChannelFieldsTable::class,
        BinanceBtcTrackerTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
        BinanceBtcTrackerTableContext::hilosI18nLanguageNames => HilosI18nLanguageNamesTable::class,
        BinanceBtcTrackerTableContext::hilosI18nCountryNames => HilosI18nCountryNamesTable::class,
        BinanceBtcTrackerTableContext::hilosI18nLanguageLocales => HilosI18nLanguageLocalesTable::class,
        BinanceBtcTrackerTableContext::hilosI18nLanguages => HilosI18nLanguagesTable::class,
        BinanceBtcTrackerTableContext::hilosI18nCountries => HilosI18nCountriesTable::class,
        BinanceBtcTrackerTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
        BinanceBtcTrackerTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
        BinanceBtcTrackerTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
        BinanceBtcTrackerTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
        HilosStatusComponentsTable::TABLE => HilosStatusComponentsTable::class,
        HilosStatusSubscribersTable::TABLE => HilosStatusSubscribersTable::class,
        HilosStatusWorksTable::TABLE => HilosStatusWorksTable::class,
        HilosStatusHistoryTable::TABLE => HilosStatusHistoryTable::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
    ];

    public const array BROWSER_DATA = [
        LanguageCardBrowserData::DATA => LanguageCardBrowserData::class,
        CountryCardBrowserData::DATA => CountryCardBrowserData::class,
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
            BinanceBtcTrackerTableContext::hilosTermsHistory => [],
        ],
        PrivacyHistoryPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosPrivacyHistory => [],
        ],
        ChangeLogFeedPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosChangeLogFeed => [],
        ],
        ChangeLogTablesPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosChangeLogTables => [],
        ],
        ChangeLogNoisyPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosChangeLogNoisyColumns => [],
        ],
        ChangeLogTableHistoryPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosChangeLogHistory => [],
        ],
        ChangeLogTableColumnsPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosChangeLogTableColumns => [],
        ],
        ChangeLogTableTriggersPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosChangeLogTableTriggers => [],
        ],
        BrowserSessionPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosAnalyticsActions => [],
        ],
        PersonPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosAnalyticsBrowserSessions => [],
        ],
        BrowserSessionsPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosAnalyticsBrowserSessionList => [],
        ],
        PeoplePage::PAGE => [
            BinanceBtcTrackerTableContext::hilosAnalyticsPeople => [],
        ],
        BackupPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosBackups => [],
        ],
        MaintenanceCirclePage::PAGE => [
            BinanceBtcTrackerTableContext::hilosVerifierCircle => [],
        ],
        MaintenanceWordsPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosMaintenanceWords => [],
        ],
        MaintenanceNodesPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosMaintenanceNodes => [],
        ],
        SettingsPage::PAGE => [
            BinanceBtcTrackerTableContext::settings => [],
        ],
        AppearancePage::PAGE => [
            BinanceBtcTrackerTableContext::hilosAppearanceSettings => [],
        ],
        UsersPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
        ],
        LogsOverviewPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosLogNodes => [],
        ],
        LogsKeysPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosLogKeys => [],
        ],
        LogsRotationsPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosLogRotations => [],
        ],
        LogsWorkersPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosLogWorkers => [],
        ],
        DaemonCronPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosDaemonCron => [],
        ],
        DaemonWorkersPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosDaemonWorkers => [],
        ],
        DaemonEnvPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosDaemonEnvKeys => [],
        ],
        DaemonEnvOrphansPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosDaemonEnvOrphans => [],
        ],
        DaemonPlacementPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosDaemonPlacement => [],
        ],
        DaemonAgentsPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosDaemonAgents => [],
        ],
        DaemonEnvMismatchPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosDaemonEnvMismatch => [],
        ],
        DaemonHttpServerPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosDaemonHttpRoutes => [],
        ],
        CommunicationsPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosCommunicationsChannels => [],
        ],
        CommunicationsChannelConfigurationPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosCommunicationsChannelFields => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosNotificationDeliveries => [],
        ],
        LanguagesListPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosI18nLanguages => [],
        ],
        CountriesListPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosI18nCountries => [],
        ],
        LanguageNamesPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosI18nLanguageNames => [],
        ],
        LanguageLocalesPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosI18nLanguageLocales => [],
        ],
        CountryNamesPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosI18nCountryNames => [],
        ],
        StatusComponentsPage::PAGE => [HilosStatusComponentsTable::TABLE => []],
        StatusSubscribersPage::PAGE => [HilosStatusSubscribersTable::TABLE => []],
        StatusWorksPage::PAGE => [HilosStatusWorksTable::TABLE => []],
        StatusHistoryPage::PAGE => [HilosStatusHistoryTable::TABLE => []],
    ];

    /**
     * Creates a fixture user in this demo's users collection and returns its id.
     *
     * Project side of the {@see HilosFacade::createFixtureUser()} seam (test-only user
     * seeding): the framework does not know the project's users collection, so this creates
     * the row through the existing name-only create path. Owning that collection while the
     * row is written is not this method's work - the class declares it in
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
     * Creates the binance-btc-tracker database context.
     *
     * @return BinanceBtcTrackerDbContext Binance BTC tracker database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new BinanceBtcTrackerDbContext();
    }

    /**
     * Creates the binance-btc-tracker runtime context.
     *
     * @return ?BinanceBtcTrackerRtContext Binance BTC tracker runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new BinanceBtcTrackerRtContext();
    }

    /**
     * Creates the binance-btc-tracker table context.
     *
     * @return ?BinanceBtcTrackerTableContext Binance BTC tracker table context
     */
    protected static function createTable(): ?TableContext
    {
        return new BinanceBtcTrackerTableContext();
    }

    /**
     * Creates the binance-btc-tracker browser-facing context.
     *
     * @return ?BinanceBtcTrackerBrowserContext Binance BTC tracker browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new BinanceBtcTrackerBrowserContext();
    }

    /**
     * @return ?BinanceBtcTrackerFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new BinanceBtcTrackerFsContext();
    }
}
