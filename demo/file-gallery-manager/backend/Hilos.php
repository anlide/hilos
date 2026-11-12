<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager;

use Demo\FileGalleryManager\Agents\FileGalleryManagerAgent;
use Demo\FileGalleryManager\Agents\Hilos\DataExportAgent;
use Demo\FileGalleryManager\Agents\Hilos\DemoHilosAgent;
use Demo\FileGalleryManager\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\FileGalleryManager\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\FileGalleryManager\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\FileGalleryManager\Agents\Hilos\DemoHilosLogsAgent;
use Demo\FileGalleryManager\Agents\Hilos\NotificationsLibraryAgent;
use Demo\FileGalleryManager\Agents\Hilos\SessionsLibraryAgent;
use Demo\FileGalleryManager\Agents\Hilos\UserAgent;
use Demo\FileGalleryManager\Agents\Hilos\UsersLibraryAgent;
use Demo\FileGalleryManager\Auth\FileGalleryManagerAuthMethodDirectory;
use Demo\FileGalleryManager\Browser\FileGalleryManagerBrowserContext;
use Demo\FileGalleryManager\Core\Agent\Daemon\FileGalleryManagerAgentDaemon;
use Demo\FileGalleryManager\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\FileGalleryManager\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\FileGalleryManager\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\FileGalleryManager\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\FileGalleryManager\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\FileGalleryManager\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\FileGalleryManager\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\FileGalleryManager\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\FileGalleryManager\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\FileGalleryManager\Database\FileGalleryManagerDbContext;
use Demo\FileGalleryManager\Database\Pages\PageCatalog;
use Demo\FileGalleryManager\Database\Settings\FileGalleryManagerSettingsCatalog;
use Demo\FileGalleryManager\Environment\FileGalleryManagerEnvCatalog;
use Demo\FileGalleryManager\Fs\FileGalleryManagerFsContext;
use Demo\FileGalleryManager\Groups\Hilos\NotificationsGroup;
use Demo\FileGalleryManager\Legal\FileGalleryManagerLegalCatalog;
use Demo\FileGalleryManager\Notification\FileGalleryManagerDeliveryChannelRegistry;
use Demo\FileGalleryManager\Pages\Hilos\AboutPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsChannelConfigurationPage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsChannelFramePage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsPage;
use Demo\FileGalleryManager\Pages\Hilos\DashboardPage;
use Demo\FileGalleryManager\Pages\Hilos\AnalyticsPage;
use Demo\FileGalleryManager\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\FileGalleryManager\Pages\Hilos\Analytics\PersonPage;
use Demo\FileGalleryManager\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\FileGalleryManager\Pages\Hilos\Analytics\PeoplePage;
use Demo\FileGalleryManager\Pages\Hilos\LicensePage;
use Demo\FileGalleryManager\Pages\Hilos\LicenseDependenciesPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\FileGalleryManager\Pages\Hilos\I18nPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsKeysPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsViewPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsWorkersPage;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Demo\FileGalleryManager\Pages\Hilos\PrivacyPage;
use Demo\FileGalleryManager\Pages\Hilos\PrivacyHistoryPage;
use Demo\FileGalleryManager\Pages\Hilos\ProfileNotificationsPage;
use Demo\FileGalleryManager\Pages\Hilos\ProfilePage;
use Demo\FileGalleryManager\Pages\Hilos\SettingsPage;
use Demo\FileGalleryManager\Pages\Hilos\TermsPage;
use Demo\FileGalleryManager\Pages\Hilos\TermsHistoryPage;
use Demo\FileGalleryManager\Pages\Hilos\Users\UserPage;
use Demo\FileGalleryManager\Pages\Hilos\Users\UsersPage;
use Demo\FileGalleryManager\Pages\MainPage;
use Demo\FileGalleryManager\Runtime\View\Context\FileGalleryManagerRtContext;
use Demo\FileGalleryManager\Tables\FileGalleryManagerTableContext;
use Demo\FileGalleryManager\Tables\HilosUser\HilosUsersTable;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
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
use Hilos\Hilos as HilosFacade;
use Hilos\HilosException;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogAggregatorAgentDaemon;
use Hilos\Log\LogCarrierAgent;
use Hilos\Log\LogCarrierAgentDaemon;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\LogStoreAgentDaemon;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
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
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;

/**
 * Hilos - Main app facade for data access.
 *
 * An empty React carrier of the framework's admin sections: sign-in by password, an empty home,
 * the admin dashboard and the public footer pages, with Settings, Users, Analytics,
 * Communications, Languages and countries, Daemon, Logs and Change Log switched on. The demo has no product of its own;
 * a product's tables, agents and pages would arrive with their own leaves.
 *
 * The profile root and its notification channels are a person's own pages, and notifications
 * are delivered by email.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::DB_DATABASE]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read FileGalleryManagerDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read FileGalleryManagerRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read FileGalleryManagerTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read FileGalleryManagerBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
class Hilos extends HilosFacade
{
    /**
     * @var array<string, list<TruthSourceOperation>> The users collection, named here because
     *     only the project knows its name. The claim is laid by the runner of a test-only
     *     command ({@see TestOnlyCommand}), which is the only thing that writes this table
     *     with no agent behind it.
     */
    public const array OWNS_DB = [FileGalleryManagerDbContext::users => TruthSourceOperation::BY_KIND];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        BrowserSessionsPage::PAGE => BrowserSessionsPage::class,
        PeoplePage::PAGE => PeoplePage::class,
        BrowserSessionPage::PAGE => BrowserSessionPage::class,
        PersonPage::PAGE => PersonPage::class,
        ChangeLogDashboardPage::PAGE => ChangeLogDashboardPage::class,
        ChangeLogFeedPage::PAGE => ChangeLogFeedPage::class,
        ChangeLogNoisyPage::PAGE => ChangeLogNoisyPage::class,
        ChangeLogTablesPage::PAGE => ChangeLogTablesPage::class,
        ChangeLogTablePage::PAGE => ChangeLogTablePage::class,
        ChangeLogTableColumnsPage::PAGE => ChangeLogTableColumnsPage::class,
        ChangeLogTableTriggersPage::PAGE => ChangeLogTableTriggersPage::class,
        ChangeLogTableHistoryPage::PAGE => ChangeLogTableHistoryPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
        I18nPage::PAGE => I18nPage::class,
        LanguagesListPage::PAGE => LanguagesListPage::class,
        CountriesListPage::PAGE => CountriesListPage::class,
        LanguageDetailPage::PAGE => LanguageDetailPage::class,
        LanguageNamesPage::PAGE => LanguageNamesPage::class,
        LanguageLocalesPage::PAGE => LanguageLocalesPage::class,
        CountryDetailPage::PAGE => CountryDetailPage::class,
        CountryNamesPage::PAGE => CountryNamesPage::class,
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
        FileGalleryManagerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => FileGalleryManagerAgent::class,
            AgentRegistryKey::DAEMON => FileGalleryManagerAgentDaemon::class,
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
        AuthThrottleAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AuthThrottleAgent::class,
            AgentRegistryKey::DAEMON => AuthThrottleAgentDaemon::class,
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
    ];

    /** @var array<string, list<string>> Outstanding closed DB writer transitions. */
    public const array SHARED_DB_OWNERS = [
        HilosDbContext::accountDeletions => ['HIL-1952'],
        HilosDbContext::dataExports => ['HIL-1952'],
        HilosDbContext::identities => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::legalAcceptances => ['HIL-1952'],
        HilosDbContext::notificationDeliveries => ['HIL-1952'],
        HilosDbContext::notificationPreferences => ['HIL-1952'],
        HilosDbContext::notifications => ['HIL-1952'],
        HilosDbContext::passkeyCredentials => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::pushSubscriptions => ['HIL-1952'],
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
        FileGalleryManagerTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
        FileGalleryManagerTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
        FileGalleryManagerTableContext::hilosChangeLogFeed => HilosChangeLogFeedTable::class,
        FileGalleryManagerTableContext::hilosChangeLogHistory => HilosChangeLogHistoryTable::class,
        FileGalleryManagerTableContext::hilosChangeLogTableColumns => HilosChangeLogTableColumnsTable::class,
        FileGalleryManagerTableContext::hilosChangeLogTableTriggers => HilosChangeLogTableTriggersTable::class,
        FileGalleryManagerTableContext::hilosChangeLogNoisyColumns => HilosChangeLogNoisyColumnsTable::class,
        FileGalleryManagerTableContext::hilosChangeLogTables => HilosChangeLogTablesTable::class,
        FileGalleryManagerTableContext::settings => HilosSettingsTable::class,
        FileGalleryManagerTableContext::hilosUsers => HilosUsersTable::class,
        FileGalleryManagerTableContext::hilosLogKeys => HilosLogKeysTable::class,
        FileGalleryManagerTableContext::hilosLogNodes => HilosLogNodesTable::class,
        FileGalleryManagerTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        FileGalleryManagerTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        FileGalleryManagerTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
        FileGalleryManagerTableContext::hilosDaemonWorkers => HilosDaemonWorkersTable::class,
        FileGalleryManagerTableContext::hilosDaemonEnvKeys => HilosDaemonEnvKeysTable::class,
        FileGalleryManagerTableContext::hilosDaemonEnvOrphans => HilosDaemonEnvOrphansTable::class,
        FileGalleryManagerTableContext::hilosDaemonAgents => HilosDaemonAgentsTable::class,
        FileGalleryManagerTableContext::hilosDaemonEnvMismatch => HilosDaemonEnvMismatchTable::class,
        FileGalleryManagerTableContext::hilosDaemonHttpRoutes => HilosDaemonHttpRoutesTable::class,
        FileGalleryManagerTableContext::hilosDaemonPlacement => HilosDaemonPlacementTable::class,
        FileGalleryManagerTableContext::hilosCommunicationsChannels => HilosCommunicationsChannelsTable::class,
        FileGalleryManagerTableContext::hilosCommunicationsChannelFields => HilosCommunicationsChannelFieldsTable::class,
        FileGalleryManagerTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
        FileGalleryManagerTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
        FileGalleryManagerTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
        FileGalleryManagerTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
        FileGalleryManagerTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
        FileGalleryManagerTableContext::hilosI18nLanguageNames => HilosI18nLanguageNamesTable::class,
        FileGalleryManagerTableContext::hilosI18nCountryNames => HilosI18nCountryNamesTable::class,
        FileGalleryManagerTableContext::hilosI18nLanguageLocales => HilosI18nLanguageLocalesTable::class,
        FileGalleryManagerTableContext::hilosI18nLanguages => HilosI18nLanguagesTable::class,
        FileGalleryManagerTableContext::hilosI18nCountries => HilosI18nCountriesTable::class,
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
    ];

    public const array PAGE_LISTS = [
        ProfilePage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileNotificationsPage::PAGE => [
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
            FileGalleryManagerTableContext::hilosTermsHistory => [],
        ],
        PrivacyHistoryPage::PAGE => [
            FileGalleryManagerTableContext::hilosPrivacyHistory => [],
        ],
        ChangeLogFeedPage::PAGE => [
            FileGalleryManagerTableContext::hilosChangeLogFeed => [],
        ],
        ChangeLogTablesPage::PAGE => [
            FileGalleryManagerTableContext::hilosChangeLogTables => [],
        ],
        ChangeLogNoisyPage::PAGE => [
            FileGalleryManagerTableContext::hilosChangeLogNoisyColumns => [],
        ],
        ChangeLogTableHistoryPage::PAGE => [
            FileGalleryManagerTableContext::hilosChangeLogHistory => [],
        ],
        ChangeLogTableColumnsPage::PAGE => [
            FileGalleryManagerTableContext::hilosChangeLogTableColumns => [],
        ],
        ChangeLogTableTriggersPage::PAGE => [
            FileGalleryManagerTableContext::hilosChangeLogTableTriggers => [],
        ],
        BrowserSessionPage::PAGE => [
            FileGalleryManagerTableContext::hilosAnalyticsActions => [],
        ],
        PersonPage::PAGE => [
            FileGalleryManagerTableContext::hilosAnalyticsBrowserSessions => [],
        ],
        BrowserSessionsPage::PAGE => [
            FileGalleryManagerTableContext::hilosAnalyticsBrowserSessionList => [],
        ],
        PeoplePage::PAGE => [
            FileGalleryManagerTableContext::hilosAnalyticsPeople => [],
        ],
        SettingsPage::PAGE => [
            FileGalleryManagerTableContext::settings => [],
        ],
        UsersPage::PAGE => [
            FileGalleryManagerTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
        ],
        LanguagesListPage::PAGE => [
            FileGalleryManagerTableContext::hilosI18nLanguages => [],
        ],
        CountriesListPage::PAGE => [
            FileGalleryManagerTableContext::hilosI18nCountries => [],
        ],
        LanguageNamesPage::PAGE => [
            FileGalleryManagerTableContext::hilosI18nLanguageNames => [],
        ],
        LanguageLocalesPage::PAGE => [
            FileGalleryManagerTableContext::hilosI18nLanguageLocales => [],
        ],
        CountryNamesPage::PAGE => [
            FileGalleryManagerTableContext::hilosI18nCountryNames => [],
        ],
        LogsOverviewPage::PAGE => [
            FileGalleryManagerTableContext::hilosLogNodes => [],
        ],
        LogsKeysPage::PAGE => [
            FileGalleryManagerTableContext::hilosLogKeys => [],
        ],
        LogsRotationsPage::PAGE => [
            FileGalleryManagerTableContext::hilosLogRotations => [],
        ],
        LogsWorkersPage::PAGE => [
            FileGalleryManagerTableContext::hilosLogWorkers => [],
        ],
        DaemonCronPage::PAGE => [
            FileGalleryManagerTableContext::hilosDaemonCron => [],
        ],
        DaemonWorkersPage::PAGE => [
            FileGalleryManagerTableContext::hilosDaemonWorkers => [],
        ],
        DaemonEnvPage::PAGE => [
            FileGalleryManagerTableContext::hilosDaemonEnvKeys => [],
        ],
        DaemonEnvOrphansPage::PAGE => [
            FileGalleryManagerTableContext::hilosDaemonEnvOrphans => [],
        ],
        DaemonPlacementPage::PAGE => [
            FileGalleryManagerTableContext::hilosDaemonPlacement => [],
        ],
        DaemonAgentsPage::PAGE => [
            FileGalleryManagerTableContext::hilosDaemonAgents => [],
        ],
        DaemonEnvMismatchPage::PAGE => [
            FileGalleryManagerTableContext::hilosDaemonEnvMismatch => [],
        ],
        DaemonHttpServerPage::PAGE => [
            FileGalleryManagerTableContext::hilosDaemonHttpRoutes => [],
        ],
        CommunicationsPage::PAGE => [
            FileGalleryManagerTableContext::hilosCommunicationsChannels => [],
        ],
        CommunicationsChannelConfigurationPage::PAGE => [
            FileGalleryManagerTableContext::hilosCommunicationsChannelFields => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            FileGalleryManagerTableContext::hilosNotificationDeliveries => [],
        ],
    ];

    protected const string ENV_CATALOG = FileGalleryManagerEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = FileGalleryManagerAuthMethodDirectory::class;

    protected const ?string LEGAL_CATALOG = FileGalleryManagerLegalCatalog::class;

    protected const string SETTINGS_CATALOG = FileGalleryManagerSettingsCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = FileGalleryManagerDeliveryChannelRegistry::class;

    protected const array FEATURES = [
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::SETTINGS,
        HilosFeature::HILOS_USERS,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
        HilosFeature::ANALYTICS,
        HilosFeature::I18N,
        HilosFeature::DAEMON,
        HilosFeature::LOGS,
        HilosFeature::CHANGE_LOG,
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
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
     * Creates the file-gallery-manager database context.
     *
     * @return FileGalleryManagerDbContext File Gallery Manager database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FileGalleryManagerDbContext();
    }

    /**
     * Creates the file-gallery-manager runtime context.
     *
     * @return ?FileGalleryManagerRtContext File Gallery Manager runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new FileGalleryManagerRtContext();
    }

    /**
     * Creates the file-gallery-manager table context.
     *
     * @return ?FileGalleryManagerTableContext File Gallery Manager table context
     */
    protected static function createTable(): ?TableContext
    {
        return new FileGalleryManagerTableContext();
    }

    /**
     * Creates the file-gallery-manager browser-facing context.
     *
     * @return ?FileGalleryManagerBrowserContext File Gallery Manager browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new FileGalleryManagerBrowserContext();
    }

    /**
     * @return ?FileGalleryManagerFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new FileGalleryManagerFsContext();
    }
}
