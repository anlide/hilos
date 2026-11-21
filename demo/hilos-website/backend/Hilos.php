<?php

declare(strict_types=1);

namespace Demo\HilosWebsite;

use Demo\HilosWebsite\Agents\HilosWebsiteAgent;
use Demo\HilosWebsite\Agents\Hilos\DataExportAgent;
use Demo\HilosWebsite\Agents\Hilos\DemoHilosAgent;
use Demo\HilosWebsite\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\HilosWebsite\Agents\Hilos\NotificationsLibraryAgent;
use Demo\HilosWebsite\Agents\Hilos\SessionsLibraryAgent;
use Demo\HilosWebsite\Agents\Hilos\UserAgent;
use Demo\HilosWebsite\Agents\Hilos\UsersLibraryAgent;
use Demo\HilosWebsite\Auth\HilosWebsiteAuthMethodDirectory;
use Demo\HilosWebsite\Browser\HilosWebsiteBrowserContext;
use Demo\HilosWebsite\Core\Agent\Daemon\HilosWebsiteAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\HilosWebsite\Database\HilosWebsiteDbContext;
use Demo\HilosWebsite\Database\Pages\PageCatalog;
use Demo\HilosWebsite\Database\Settings\HilosWebsiteSettingsCatalog;
use Demo\HilosWebsite\Environment\HilosWebsiteEnvCatalog;
use Demo\HilosWebsite\Fs\HilosWebsiteFsContext;
use Demo\HilosWebsite\Groups\Hilos\NotificationsGroup;
use Demo\HilosWebsite\Legal\HilosWebsiteLegalCatalog;
use Demo\HilosWebsite\Notification\HilosWebsiteDeliveryChannelRegistry;
use Demo\HilosWebsite\Pages\Hilos\AboutPage;
use Demo\HilosWebsite\Pages\Hilos\DashboardPage;
use Demo\HilosWebsite\Pages\Hilos\AnalyticsPage;
use Demo\HilosWebsite\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\HilosWebsite\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\HilosWebsite\Pages\Hilos\Analytics\PeoplePage;
use Demo\HilosWebsite\Pages\Hilos\Analytics\PersonPage;
use Demo\HilosWebsite\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\HilosWebsite\Pages\Hilos\LicensePage;
use Demo\HilosWebsite\Pages\Hilos\LicenseDependenciesPage;
use Demo\HilosWebsite\Pages\Hilos\PrivacyPage;
use Demo\HilosWebsite\Pages\Hilos\PrivacyHistoryPage;
use Demo\HilosWebsite\Pages\Hilos\ProfileDataPage;
use Demo\HilosWebsite\Pages\Hilos\ProfilePage;
use Demo\HilosWebsite\Pages\Hilos\ProfileSignInPage;
use Demo\HilosWebsite\Pages\Hilos\SettingsPage;
use Demo\HilosWebsite\Pages\Hilos\TermsPage;
use Demo\HilosWebsite\Pages\Hilos\TermsHistoryPage;
use Demo\HilosWebsite\Pages\MainPage;
use Demo\HilosWebsite\Runtime\View\Context\HilosWebsiteRtContext;
use Demo\HilosWebsite\Tables\HilosWebsiteTableContext;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
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
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;

/**
 * Hilos - Main app facade for data access.
 *
 * The official Hilos site: a public home page, and behind /hilos the owner's account with
 * the profile, settings, Analytics and the delivery journal of its email channel.
 * Registration is closed - the one account is handed out by admin:create.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read HilosWebsiteDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read HilosWebsiteRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read HilosWebsiteTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read HilosWebsiteBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        BrowserSessionsPage::PAGE => BrowserSessionsPage::class,
        BrowserSessionPage::PAGE => BrowserSessionPage::class,
        PeoplePage::PAGE => PeoplePage::class,
        PersonPage::PAGE => PersonPage::class,
        CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        ProfilePage::PAGE => ProfilePage::class,
        ProfileDataPage::PAGE => ProfileDataPage::class,
        ProfileSignInPage::PAGE => ProfileSignInPage::class,
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
        HilosWebsiteAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => HilosWebsiteAgent::class,
            AgentRegistryKey::DAEMON => HilosWebsiteAgentDaemon::class,
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
        SettingsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SettingsLibraryAgent::class,
            AgentRegistryKey::DAEMON => SettingsLibraryAgentDaemon::class,
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
        AuthThrottleAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AuthThrottleAgent::class,
            AgentRegistryKey::DAEMON => AuthThrottleAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        NotificationsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => NotificationsLibraryAgent::class,
            AgentRegistryKey::DAEMON => NotificationsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
    ];

    public const array TABLES = [
        HilosWebsiteTableContext::settings => HilosSettingsTable::class,
        HilosWebsiteTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
        HilosWebsiteTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
        HilosWebsiteTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
        HilosWebsiteTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
        HilosWebsiteTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
        HilosWebsiteTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
        HilosWebsiteTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
    ];

    public const array BROWSER_LISTS = [
        HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::class,
    ];

    public const array PAGE_LISTS = [
        ProfilePage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileDataPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileSignInPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
    ];

    public const array PAGE_TABLES = [
        BrowserSessionsPage::PAGE => [
            HilosWebsiteTableContext::hilosAnalyticsBrowserSessionList => [],
        ],
        BrowserSessionPage::PAGE => [
            HilosWebsiteTableContext::hilosAnalyticsActions => [],
        ],
        PeoplePage::PAGE => [
            HilosWebsiteTableContext::hilosAnalyticsPeople => [],
        ],
        PersonPage::PAGE => [
            HilosWebsiteTableContext::hilosAnalyticsBrowserSessions => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            HilosWebsiteTableContext::hilosNotificationDeliveries => [],
        ],
        SettingsPage::PAGE => [
            HilosWebsiteTableContext::settings => [],
        ],
        TermsHistoryPage::PAGE => [
            HilosWebsiteTableContext::hilosTermsHistory => [],
        ],
        PrivacyHistoryPage::PAGE => [
            HilosWebsiteTableContext::hilosPrivacyHistory => [],
        ],
    ];

    protected const string ENV_CATALOG = HilosWebsiteEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = HilosWebsiteAuthMethodDirectory::class;

    protected const string SETTINGS_CATALOG = HilosWebsiteSettingsCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = HilosWebsiteDeliveryChannelRegistry::class;

    protected const ?string LEGAL_CATALOG = HilosWebsiteLegalCatalog::class;

    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::ANALYTICS,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    /**
     * Creates the hilos-website database context.
     *
     * @return HilosWebsiteDbContext Hilos-website database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new HilosWebsiteDbContext();
    }

    /**
     * Creates the hilos-website runtime context.
     *
     * @return ?HilosWebsiteRtContext Hilos-website runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new HilosWebsiteRtContext();
    }

    /**
     * Creates the hilos-website table context.
     *
     * @return ?HilosWebsiteTableContext Hilos-website table context
     */
    protected static function createTable(): ?TableContext
    {
        return new HilosWebsiteTableContext();
    }

    /**
     * Creates the hilos-website browser-facing context.
     *
     * @return ?HilosWebsiteBrowserContext Hilos-website browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new HilosWebsiteBrowserContext();
    }

    /**
     * @return ?HilosWebsiteFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new HilosWebsiteFsContext();
    }
}
