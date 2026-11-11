<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer;

use Demo\RobloxGameServer\Agents\RobloxGameServerAgent;
use Demo\RobloxGameServer\Agents\Hilos\DataExportAgent;
use Demo\RobloxGameServer\Agents\Hilos\DemoHilosAgent;
use Demo\RobloxGameServer\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\RobloxGameServer\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\RobloxGameServer\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\RobloxGameServer\Agents\Hilos\DemoHilosLogsAgent;
use Demo\RobloxGameServer\Agents\Hilos\FilesLibraryAgent;
use Demo\RobloxGameServer\Agents\Hilos\NotificationsLibraryAgent;
use Demo\RobloxGameServer\Agents\Hilos\SessionsLibraryAgent;
use Demo\RobloxGameServer\Agents\Hilos\UserAgent;
use Demo\RobloxGameServer\Agents\Hilos\UsersLibraryAgent;
use Demo\RobloxGameServer\Auth\RobloxGameServerAuthMethodDirectory;
use Demo\RobloxGameServer\Backup\BackupCatalog;
use Demo\RobloxGameServer\Browser\Data\SelfConnectionBrowserData;
use Demo\RobloxGameServer\Browser\RobloxGameServerBrowserContext;
use Demo\RobloxGameServer\Core\Agent\Daemon\RobloxGameServerAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\FilesLibraryAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\RobloxGameServer\Database\RobloxGameServerDbContext;
use Demo\RobloxGameServer\Database\Pages\PageCatalog;
use Demo\RobloxGameServer\Database\Settings\RobloxGameServerSettingsCatalog;
use Demo\RobloxGameServer\Environment\RobloxGameServerEnvCatalog;
use Demo\RobloxGameServer\Fs\RobloxGameServerFsContext;
use Demo\RobloxGameServer\Groups\Hilos\NotificationsGroup;
use Demo\RobloxGameServer\Legal\RobloxGameServerLegalCatalog;
use Demo\RobloxGameServer\Notification\RobloxGameServerDeliveryChannelRegistry;
use Demo\RobloxGameServer\Pages\Hilos\AboutPage;
use Demo\RobloxGameServer\Pages\Hilos\Backup\BackupPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Demo\RobloxGameServer\Pages\Hilos\Communications\CommunicationsChannelConfigurationPage;
use Demo\RobloxGameServer\Pages\Hilos\Communications\CommunicationsChannelFramePage;
use Demo\RobloxGameServer\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\RobloxGameServer\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\RobloxGameServer\Pages\Hilos\Communications\CommunicationsPage;
use Demo\RobloxGameServer\Pages\Hilos\DashboardPage;
use Demo\RobloxGameServer\Pages\Hilos\AnalyticsPage;
use Demo\RobloxGameServer\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\RobloxGameServer\Pages\Hilos\Analytics\PersonPage;
use Demo\RobloxGameServer\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\RobloxGameServer\Pages\Hilos\Analytics\PeoplePage;
use Demo\RobloxGameServer\Pages\Hilos\LicensePage;
use Demo\RobloxGameServer\Pages\Hilos\LicenseDependenciesPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsKeysPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsViewPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\RobloxGameServer\Pages\Hilos\Maintenance\MaintenanceCirclePage;
use Demo\RobloxGameServer\Pages\Hilos\Maintenance\MaintenanceNodesPage;
use Demo\RobloxGameServer\Pages\Hilos\Maintenance\MaintenanceWordsPage;
use Demo\RobloxGameServer\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\RobloxGameServer\Pages\Hilos\Moderation\ModerationKindPage;
use Demo\RobloxGameServer\Pages\Hilos\Moderation\ModerationKindRulesPage;
use Demo\RobloxGameServer\Pages\Hilos\Moderation\ModerationPage;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Demo\RobloxGameServer\Pages\Hilos\PrivacyPage;
use Demo\RobloxGameServer\Pages\Hilos\PrivacyHistoryPage;
use Demo\RobloxGameServer\Pages\Hilos\ProfileNotificationsPage;
use Demo\RobloxGameServer\Pages\Hilos\ProfilePage;
use Demo\RobloxGameServer\Pages\Hilos\SettingsPage;
use Demo\RobloxGameServer\Pages\Hilos\AppearancePage;
use Demo\RobloxGameServer\Pages\Hilos\TermsPage;
use Demo\RobloxGameServer\Pages\Hilos\TermsHistoryPage;
use Demo\RobloxGameServer\Pages\Hilos\Users\UserPage;
use Demo\RobloxGameServer\Pages\Hilos\Users\UserSessionsPage;
use Demo\RobloxGameServer\Pages\Hilos\Users\UsersPage;
use Demo\RobloxGameServer\Pages\MainPage;
use Demo\RobloxGameServer\Runtime\View\Context\RobloxGameServerRtContext;
use Demo\RobloxGameServer\Tables\RobloxGameServerTableContext;
use Demo\RobloxGameServer\Tables\HilosUser\HilosUsersTable;
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
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Image\ImagesAgentDaemon;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Files\Upload\UploadsAgentDaemon;
use Hilos\Fs\Context\FsContext;
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
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\Library\ModerationRulesLibraryAgentDaemon;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationAgentDaemon;
use Hilos\Push\Delivery\PushDeliveryChannelAgent;
use Hilos\Push\Delivery\PushDeliveryChannelAgentDaemon;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgentDaemon;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
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
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Tables\Moderation\HilosModerationRulesTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosUserPhotoBrowserTable;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\Pages\Users\HilosUserActingSessionsBrowserList;
use Hilos\Pages\Users\HilosUserSessionsBrowserList;

/**
 * Hilos - Main app facade for data access.
 *
 * An empty Roblox game server: sign-in by password, an empty home, the admin dashboard and the
 * public footer pages, with the framework's operation sections switched on - Maintenance,
 * Backup, Settings and Appearance, Users, Logs, Daemon, Communications, Change Log, Analytics
 * and Moderation. The product of the game server is not here yet; its own tables, agents and
 * pages arrive with their own leaves.
 *
 * The profile root and its notification channels are a person's own pages: the profile carries
 * the photo, which goes through uploads, images and the moderation check, and notifications
 * are delivered by email, SMS and web push.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read RobloxGameServerDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read RobloxGameServerRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read RobloxGameServerTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read RobloxGameServerBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
class Hilos extends HilosFacade
{
    /**
     * @var array<string, list<TruthSourceOperation>> The users collection, named here because
     *     only the project knows its name. The claim is laid by the runner of a test-only
     *     command ({@see TestOnlyCommand}), which is the only thing that writes this table
     *     with no agent behind it.
     */
    public const array OWNS_DB = [RobloxGameServerDbContext::users => TruthSourceOperation::BY_KIND];

    protected const string ENV_CATALOG = RobloxGameServerEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = RobloxGameServerAuthMethodDirectory::class;

    protected const ?string LEGAL_CATALOG = RobloxGameServerLegalCatalog::class;

    protected const ?string BACKUP_CATALOG = BackupCatalog::class;

    protected const string SETTINGS_CATALOG = RobloxGameServerSettingsCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = RobloxGameServerDeliveryChannelRegistry::class;

    protected const array FEATURES = [
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::SETTINGS,
        HilosFeature::HILOS_USERS,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
        HilosFeature::ANALYTICS,
        HilosFeature::CHANGE_LOG,
        HilosFeature::MODERATION,
        HilosFeature::FILES,
        HilosFeature::UPLOADS,
        HilosFeature::IMAGES,
        HilosFeature::PROFILE_PHOTO,
        HilosFeature::DAEMON,
        HilosFeature::BACKUP,
        HilosFeature::LOGS,
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
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
        UserSessionsPage::PAGE => UserSessionsPage::class,
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
        ProfilePage::PAGE => ProfilePage::class,
        ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
        ModerationPage::PAGE => ModerationPage::class,
        ModerationKindPage::PAGE => ModerationKindPage::class,
        ModerationKindRulesPage::PAGE => ModerationKindRulesPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        TermsHistoryPage::PAGE => TermsHistoryPage::class,
        PrivacyHistoryPage::PAGE => PrivacyHistoryPage::class,
        LicensePage::PAGE => LicensePage::class,
        LicenseDependenciesPage::PAGE => LicenseDependenciesPage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        RobloxGameServerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => RobloxGameServerAgent::class,
            AgentRegistryKey::DAEMON => RobloxGameServerAgentDaemon::class,
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
        LogAggregatorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogAggregatorAgent::class,
            AgentRegistryKey::DAEMON => LogAggregatorAgentDaemon::class,
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
        ModerationAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ModerationAgent::class,
            AgentRegistryKey::DAEMON => ModerationAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        ModerationRulesLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ModerationRulesLibraryAgent::class,
            AgentRegistryKey::DAEMON => ModerationRulesLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
    ];

    /** @var array<string, list<string>> Outstanding closed DB writer transitions. */
    public const array SHARED_DB_OWNERS = [
        HilosDbContext::accountDeletions => ['HIL-1952'],
        HilosDbContext::dataExports => ['HIL-1952'],
        HilosDbContext::identities => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::legalAcceptances => ['HIL-1952'],
        HilosDbContext::notificationDeliveries => ['HIL-1910', 'HIL-1952'],
        HilosDbContext::notificationPreferences => ['HIL-1952'],
        HilosDbContext::notifications => ['HIL-1952'],
        HilosDbContext::passkeyCredentials => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::pushSubscriptions => ['HIL-1905', 'HIL-1906', 'HIL-1952'],
        HilosDbContext::secondFactorBackupCodes => ['HIL-1904', 'HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactorResets => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactorSettings => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactorTrusts => ['HIL-1907', 'HIL-1908', 'HIL-1909', 'HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactors => ['HIL-1903', 'HIL-1952', 'HIL-1999'],
        HilosDbContext::stepUps => ['HIL-1952'],
        HilosDbContext::userPhotos => ['HIL-1952'],
        HilosDbContext::userRenames => ['HIL-1952'],
        HilosDbContext::users => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::verifications => ['HIL-1952'],
    ];

    public const array TABLES = [
        RobloxGameServerTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
        RobloxGameServerTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
        RobloxGameServerTableContext::hilosChangeLogFeed => HilosChangeLogFeedTable::class,
        RobloxGameServerTableContext::hilosChangeLogHistory => HilosChangeLogHistoryTable::class,
        RobloxGameServerTableContext::hilosChangeLogTableColumns => HilosChangeLogTableColumnsTable::class,
        RobloxGameServerTableContext::hilosChangeLogTableTriggers => HilosChangeLogTableTriggersTable::class,
        RobloxGameServerTableContext::hilosChangeLogNoisyColumns => HilosChangeLogNoisyColumnsTable::class,
        RobloxGameServerTableContext::hilosChangeLogTables => HilosChangeLogTablesTable::class,
        RobloxGameServerTableContext::hilosBackups => HilosBackupHistoryTable::class,
        RobloxGameServerTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
        RobloxGameServerTableContext::hilosMaintenanceNodes => HilosMaintenanceNodesTable::class,
        RobloxGameServerTableContext::hilosMaintenanceWords => HilosMaintenanceWordsTable::class,
        RobloxGameServerTableContext::settings => HilosSettingsTable::class,
        RobloxGameServerTableContext::hilosAppearanceSettings => HilosAppearanceSettingsTable::class,
        RobloxGameServerTableContext::hilosUsers => HilosUsersTable::class,
        RobloxGameServerTableContext::hilosLogKeys => HilosLogKeysTable::class,
        RobloxGameServerTableContext::hilosLogNodes => HilosLogNodesTable::class,
        RobloxGameServerTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        RobloxGameServerTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        RobloxGameServerTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
        RobloxGameServerTableContext::hilosDaemonWorkers => HilosDaemonWorkersTable::class,
        RobloxGameServerTableContext::hilosDaemonEnvKeys => HilosDaemonEnvKeysTable::class,
        RobloxGameServerTableContext::hilosDaemonEnvOrphans => HilosDaemonEnvOrphansTable::class,
        RobloxGameServerTableContext::hilosDaemonAgents => HilosDaemonAgentsTable::class,
        RobloxGameServerTableContext::hilosDaemonEnvMismatch => HilosDaemonEnvMismatchTable::class,
        RobloxGameServerTableContext::hilosDaemonHttpRoutes => HilosDaemonHttpRoutesTable::class,
        RobloxGameServerTableContext::hilosDaemonPlacement => HilosDaemonPlacementTable::class,
        RobloxGameServerTableContext::hilosCommunicationsChannels => HilosCommunicationsChannelsTable::class,
        RobloxGameServerTableContext::hilosCommunicationsChannelFields => HilosCommunicationsChannelFieldsTable::class,
        RobloxGameServerTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
        RobloxGameServerTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
        RobloxGameServerTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
        RobloxGameServerTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
        RobloxGameServerTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
        RobloxGameServerTableContext::hilosModerationKinds => HilosModerationKindsTable::class,
        RobloxGameServerTableContext::hilosModerationRules => HilosModerationRulesTable::class,
    ];

    public const array BROWSER_LISTS = [
        HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::class,
        HilosUserSessionsBrowserList::LIST => HilosUserSessionsBrowserList::class,
        HilosUserActingSessionsBrowserList::LIST => HilosUserActingSessionsBrowserList::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
        HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::class,
    ];

    public const array BROWSER_DATA = [
        SelfConnectionBrowserData::DATA => SelfConnectionBrowserData::class,
    ];

    public const array PAGE_LISTS = [
        ProfilePage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileNotificationsPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        UserPage::PAGE => [
            HilosUserSessionsBrowserList::LIST => HilosUserSessionsBrowserList::BINDING,
            HilosUserActingSessionsBrowserList::LIST => HilosUserActingSessionsBrowserList::BINDING,
        ],
        UserSessionsPage::PAGE => [
            HilosUserSessionsBrowserList::LIST => HilosUserSessionsBrowserList::BINDING,
            HilosUserActingSessionsBrowserList::LIST => HilosUserActingSessionsBrowserList::BINDING,
        ],
    ];

    public const array PAGE_DATA = [
        ProfilePage::PAGE => [
            SelfConnectionBrowserData::DATA => SelfConnectionBrowserData::BINDING,
        ],
    ];

    public const array PAGE_TABLES = [
        TermsHistoryPage::PAGE => [
            RobloxGameServerTableContext::hilosTermsHistory => [],
        ],
        PrivacyHistoryPage::PAGE => [
            RobloxGameServerTableContext::hilosPrivacyHistory => [],
        ],
        ChangeLogFeedPage::PAGE => [
            RobloxGameServerTableContext::hilosChangeLogFeed => [],
        ],
        ChangeLogTablesPage::PAGE => [
            RobloxGameServerTableContext::hilosChangeLogTables => [],
        ],
        ChangeLogNoisyPage::PAGE => [
            RobloxGameServerTableContext::hilosChangeLogNoisyColumns => [],
        ],
        ChangeLogTableHistoryPage::PAGE => [
            RobloxGameServerTableContext::hilosChangeLogHistory => [],
        ],
        ChangeLogTableColumnsPage::PAGE => [
            RobloxGameServerTableContext::hilosChangeLogTableColumns => [],
        ],
        ChangeLogTableTriggersPage::PAGE => [
            RobloxGameServerTableContext::hilosChangeLogTableTriggers => [],
        ],
        BrowserSessionPage::PAGE => [
            RobloxGameServerTableContext::hilosAnalyticsActions => [],
        ],
        PersonPage::PAGE => [
            RobloxGameServerTableContext::hilosAnalyticsBrowserSessions => [],
        ],
        BrowserSessionsPage::PAGE => [
            RobloxGameServerTableContext::hilosAnalyticsBrowserSessionList => [],
        ],
        PeoplePage::PAGE => [
            RobloxGameServerTableContext::hilosAnalyticsPeople => [],
        ],
        BackupPage::PAGE => [
            RobloxGameServerTableContext::hilosBackups => [],
        ],
        MaintenanceCirclePage::PAGE => [
            RobloxGameServerTableContext::hilosVerifierCircle => [],
        ],
        MaintenanceWordsPage::PAGE => [
            RobloxGameServerTableContext::hilosMaintenanceWords => [],
        ],
        MaintenanceNodesPage::PAGE => [
            RobloxGameServerTableContext::hilosMaintenanceNodes => [],
        ],
        SettingsPage::PAGE => [
            RobloxGameServerTableContext::settings => [],
        ],
        AppearancePage::PAGE => [
            RobloxGameServerTableContext::hilosAppearanceSettings => [],
        ],
        UsersPage::PAGE => [
            RobloxGameServerTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
        ],
        UserSessionsPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
        ],
        LogsOverviewPage::PAGE => [
            RobloxGameServerTableContext::hilosLogNodes => [],
        ],
        LogsKeysPage::PAGE => [
            RobloxGameServerTableContext::hilosLogKeys => [],
        ],
        LogsRotationsPage::PAGE => [
            RobloxGameServerTableContext::hilosLogRotations => [],
        ],
        LogsWorkersPage::PAGE => [
            RobloxGameServerTableContext::hilosLogWorkers => [],
        ],
        DaemonCronPage::PAGE => [
            RobloxGameServerTableContext::hilosDaemonCron => [],
        ],
        DaemonWorkersPage::PAGE => [
            RobloxGameServerTableContext::hilosDaemonWorkers => [],
        ],
        DaemonEnvPage::PAGE => [
            RobloxGameServerTableContext::hilosDaemonEnvKeys => [],
        ],
        DaemonEnvOrphansPage::PAGE => [
            RobloxGameServerTableContext::hilosDaemonEnvOrphans => [],
        ],
        DaemonPlacementPage::PAGE => [
            RobloxGameServerTableContext::hilosDaemonPlacement => [],
        ],
        DaemonAgentsPage::PAGE => [
            RobloxGameServerTableContext::hilosDaemonAgents => [],
        ],
        DaemonEnvMismatchPage::PAGE => [
            RobloxGameServerTableContext::hilosDaemonEnvMismatch => [],
        ],
        DaemonHttpServerPage::PAGE => [
            RobloxGameServerTableContext::hilosDaemonHttpRoutes => [],
        ],
        CommunicationsPage::PAGE => [
            RobloxGameServerTableContext::hilosCommunicationsChannels => [],
        ],
        CommunicationsChannelConfigurationPage::PAGE => [
            RobloxGameServerTableContext::hilosCommunicationsChannelFields => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            RobloxGameServerTableContext::hilosNotificationDeliveries => [],
        ],
        ModerationPage::PAGE => [
            RobloxGameServerTableContext::hilosModerationKinds => [],
        ],
        ModerationKindPage::PAGE => [
            RobloxGameServerTableContext::hilosModerationKinds => [],
        ],
        ModerationKindRulesPage::PAGE => [
            RobloxGameServerTableContext::hilosModerationKinds => [],
            RobloxGameServerTableContext::hilosModerationRules => [],
        ],
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
     * Creates the roblox-game-server database context.
     *
     * @return RobloxGameServerDbContext Roblox game server database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new RobloxGameServerDbContext();
    }

    /**
     * Creates the roblox-game-server runtime context.
     *
     * @return ?RobloxGameServerRtContext Roblox game server runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new RobloxGameServerRtContext();
    }

    /**
     * Creates the roblox-game-server table context.
     *
     * @return ?RobloxGameServerTableContext Roblox game server table context
     */
    protected static function createTable(): ?TableContext
    {
        return new RobloxGameServerTableContext();
    }

    /**
     * Creates the roblox-game-server browser-facing context.
     *
     * @return ?RobloxGameServerBrowserContext Roblox game server browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new RobloxGameServerBrowserContext();
    }

    /**
     * @return ?RobloxGameServerFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new RobloxGameServerFsContext();
    }
}
