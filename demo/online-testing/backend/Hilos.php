<?php

declare(strict_types=1);

namespace Demo\OnlineTesting;

use Demo\OnlineTesting\Agents\OnlineTestingAgent;
use Demo\OnlineTesting\Agents\Hilos\DataExportAgent;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosAgent;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosLogsAgent;
use Demo\OnlineTesting\Agents\Hilos\NotificationsLibraryAgent;
use Demo\OnlineTesting\Agents\Hilos\SessionsLibraryAgent;
use Demo\OnlineTesting\Agents\Hilos\UserAgent;
use Demo\OnlineTesting\Agents\Hilos\UsersLibraryAgent;
use Demo\OnlineTesting\Auth\OnlineTestingAuthMethodDirectory;
use Demo\OnlineTesting\Browser\OnlineTestingBrowserContext;
use Demo\OnlineTesting\Core\Agent\Daemon\OnlineTestingAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\OnlineTesting\Database\OnlineTestingDbContext;
use Demo\OnlineTesting\Database\Settings\OnlineTestingSettingsCatalog;
use Demo\OnlineTesting\Environment\OnlineTestingEnvCatalog;
use Demo\OnlineTesting\Fs\OnlineTestingFsContext;
use Demo\OnlineTesting\Groups\Hilos\NotificationsGroup;
use Demo\OnlineTesting\Legal\OnlineTestingLegalCatalog;
use Demo\OnlineTesting\Pages\Hilos\AboutPage;
use Demo\OnlineTesting\Pages\Hilos\DashboardPage;
use Demo\OnlineTesting\Pages\Hilos\LicensePage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsKeysPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsViewPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\OnlineTesting\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\OnlineTesting\Pages\Hilos\PrivacyPage;
use Demo\OnlineTesting\Pages\Hilos\SettingsPage;
use Demo\OnlineTesting\Pages\Hilos\I18nPage;
use Demo\OnlineTesting\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\OnlineTesting\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\OnlineTesting\Pages\Hilos\TermsPage;
use Demo\OnlineTesting\Pages\Hilos\Users\UserPage;
use Demo\OnlineTesting\Pages\Hilos\Users\UsersPage;
use Demo\OnlineTesting\Pages\MainPage;
use Demo\OnlineTesting\Runtime\View\Context\OnlineTestingRtContext;
use Demo\OnlineTesting\Tables\HilosUser\HilosUsersTable;
use Demo\OnlineTesting\Tables\OnlineTestingTableContext;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
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
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\TruthSource\SharedOwnersKey;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseGuarantee;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
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
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;

/**
 * Hilos - Main app facade for data access.
 *
 * Sign-in by password, the home and four public footer pages. The admin dashboard now opens
 * settings, the people list and detail, logs, and Maintenance. The shell notification center
 * has no delivery channels. Each section arrived with its polls e2e coverage (HIL-1226).
 *
 * Its cluster stand (docker/docker-compose.cluster.yml) runs the framework's fleet and
 * database probes.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read OnlineTestingDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read OnlineTestingRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read OnlineTestingTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read OnlineTestingBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = OnlineTestingEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = OnlineTestingAuthMethodDirectory::class;

    protected const string SETTINGS_CATALOG = OnlineTestingSettingsCatalog::class;

    protected const ?string LEGAL_CATALOG = OnlineTestingLegalCatalog::class;

    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::I18N,
        HilosFeature::HILOS_USERS,
        HilosFeature::LOGS,
        HilosFeature::DAEMON,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        I18nPage::PAGE => I18nPage::class,
        LanguagesListPage::PAGE => LanguagesListPage::class,
        CountriesListPage::PAGE => CountriesListPage::class,
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
        MaintenancePage::PAGE => MaintenancePage::class,
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicensePage::PAGE => LicensePage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        OnlineTestingAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => OnlineTestingAgent::class,
            AgentRegistryKey::DAEMON => OnlineTestingAgentDaemon::class,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
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
        MailDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => MailDeliveryChannelAgentDaemon::class,
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
        LogAggregatorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogAggregatorAgent::class,
            AgentRegistryKey::DAEMON => LogAggregatorAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        AuthThrottleAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AuthThrottleAgent::class,
            AgentRegistryKey::DAEMON => AuthThrottleAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        // The probes of this demo's cluster stand - scenario 24 reads the fleet, scenario 11
        // writes and reads through the database probe. A probe starts only on a clustered node of
        // a non-production environment, so this demo on one node, on its Playwright stand and in
        // production carries the rows and runs none of them (docs/agents/testing.md, "The cluster
        // stands").
        HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
        HilosAgentType::HILOS_PROBE_DB => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_DB],
    ];

    /**
     * The collections this demo still lets two owners hold, and who will part them.
     *
     * Receipts, not permissions: every pair here is a place where two agents write the same rows
     * today, and startup refuses both a pair that is missing from this list and a row whose
     * owners no longer collide.
     *
     * Three rows, all of them signing in: the people, their step-up proofs and the registration
     * holds, each shared by the sessions and the users library. They stand the same way in every
     * demo that switches the feature on; the rows the provider and the code agents add elsewhere
     * do not arise here, because this demo runs neither. The rows are parted by their own leaves:
     * users by HIL-1404, stepUps by HIL-1407, and registrationReservations by HIL-1411.
     * See docs/agents/architecture/instance-owners.md#where-the-pieces-land.
     */
    public const array SHARED_DB_OWNERS = [
        HilosDbContext::stepUps => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, SessionsLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1407',
        ],
        HilosDbContext::users => [
            SharedOwnersKey::OWNERS => [SessionsLibraryAgent::class, UsersLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1404',
        ],
        HilosDbContext::registrationReservations => [
            SharedOwnersKey::OWNERS => [SessionsLibraryAgent::class, UsersLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1411',
        ],
    ];

    public const array TABLES = [
        OnlineTestingTableContext::settings => HilosSettingsTable::class,
        OnlineTestingTableContext::hilosUsers => HilosUsersTable::class,
        OnlineTestingTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
        OnlineTestingTableContext::hilosLogKeys => HilosLogKeysTable::class,
        OnlineTestingTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        OnlineTestingTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
    ];

    public const array PAGE_TABLES = [
        SettingsPage::PAGE => [
            OnlineTestingTableContext::settings => [],
        ],
        MaintenancePage::PAGE => [
            OnlineTestingTableContext::hilosVerifierCircle => [],
        ],
        LogsKeysPage::PAGE => [
            OnlineTestingTableContext::hilosLogKeys => [],
        ],
        LogsRotationsPage::PAGE => [
            OnlineTestingTableContext::hilosLogRotations => [],
        ],
        LogsWorkersPage::PAGE => [
            OnlineTestingTableContext::hilosLogWorkers => [],
        ],
        UsersPage::PAGE => [
            OnlineTestingTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
        ],
    ];

    /**
     * Creates the online-testing database context.
     *
     * @return OnlineTestingDbContext Online-testing database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new OnlineTestingDbContext();
    }

    /**
     * Creates the online-testing runtime context.
     *
     * @return ?OnlineTestingRtContext Online-testing runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new OnlineTestingRtContext();
    }

    /**
     * Creates the online-testing table context.
     *
     * @return ?OnlineTestingTableContext Online-testing table context
     */
    protected static function createTable(): ?TableContext
    {
        return new OnlineTestingTableContext();
    }

    /**
     * Creates the online-testing browser-facing context.
     *
     * @return ?OnlineTestingBrowserContext Online-testing browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new OnlineTestingBrowserContext();
    }

    /**
     * @return ?OnlineTestingFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new OnlineTestingFsContext();
    }
}
