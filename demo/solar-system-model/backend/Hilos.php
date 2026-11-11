<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel;

use Demo\SolarSystemModel\Agents\Hilos\DataExportAgent;
use Demo\SolarSystemModel\Agents\Hilos\DemoHilosAgent;
use Demo\SolarSystemModel\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\SolarSystemModel\Agents\Hilos\FilesLibraryAgent;
use Demo\SolarSystemModel\Agents\Hilos\NotificationsLibraryAgent;
use Demo\SolarSystemModel\Agents\Hilos\SessionsLibraryAgent;
use Demo\SolarSystemModel\Agents\Hilos\UserAgent;
use Demo\SolarSystemModel\Agents\Hilos\UsersLibraryAgent;
use Demo\SolarSystemModel\Agents\OAuthAgent;
use Demo\SolarSystemModel\Agents\SolarSystemModelAgent;
use Demo\SolarSystemModel\Auth\SolarSystemModelAuthMethodDirectory;
use Demo\SolarSystemModel\Auth\SolarSystemModelOAuthProviderDirectory;
use Demo\SolarSystemModel\Browser\SolarSystemModelBrowserContext;
use Demo\SolarSystemModel\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\SolarSystemModel\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\SolarSystemModel\Core\Agent\Daemon\Hilos\FilesLibraryAgentDaemon;
use Demo\SolarSystemModel\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\SolarSystemModel\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\SolarSystemModel\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\SolarSystemModel\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\SolarSystemModel\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\SolarSystemModel\Core\Agent\Daemon\SolarSystemModelAgentDaemon;
use Demo\SolarSystemModel\Database\Pages\PageCatalog;
use Demo\SolarSystemModel\Database\SolarSystemModelDbContext;
use Demo\SolarSystemModel\Database\Settings\SolarSystemModelSettingsCatalog;
use Demo\SolarSystemModel\Environment\SolarSystemModelEnvCatalog;
use Demo\SolarSystemModel\Fs\SolarSystemModelFsContext;
use Demo\SolarSystemModel\Groups\Hilos\NotificationsGroup;
use Demo\SolarSystemModel\Legal\SolarSystemModelLegalCatalog;
use Demo\SolarSystemModel\Notification\SolarSystemModelDeliveryChannelRegistry;
use Demo\SolarSystemModel\Pages\Hilos\AboutPage;
use Demo\SolarSystemModel\Pages\Hilos\AnalyticsPage;
use Demo\SolarSystemModel\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\SolarSystemModel\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\SolarSystemModel\Pages\Hilos\Analytics\PeoplePage;
use Demo\SolarSystemModel\Pages\Hilos\Analytics\PersonPage;
use Demo\SolarSystemModel\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\SolarSystemModel\Pages\Hilos\DashboardPage;
use Demo\SolarSystemModel\Pages\Hilos\LicenseDependenciesPage;
use Demo\SolarSystemModel\Pages\Hilos\LicensePage;
use Demo\SolarSystemModel\Pages\Hilos\PrivacyHistoryPage;
use Demo\SolarSystemModel\Pages\Hilos\PrivacyPage;
use Demo\SolarSystemModel\Pages\Hilos\ProfileDataPage;
use Demo\SolarSystemModel\Pages\Hilos\ProfilePage;
use Demo\SolarSystemModel\Pages\Hilos\ProfileSecurityPage;
use Demo\SolarSystemModel\Pages\Hilos\ProfileSignInPage;
use Demo\SolarSystemModel\Pages\Hilos\SettingsPage;
use Demo\SolarSystemModel\Pages\Hilos\TermsHistoryPage;
use Demo\SolarSystemModel\Pages\Hilos\TermsPage;
use Demo\SolarSystemModel\Pages\Hilos\Users\UserPage;
use Demo\SolarSystemModel\Pages\Hilos\Users\UsersPage;
use Demo\SolarSystemModel\Pages\MainPage;
use Demo\SolarSystemModel\Runtime\View\Context\SolarSystemModelRtContext;
use Demo\SolarSystemModel\Tables\HilosUser\HilosUsersTable;
use Demo\SolarSystemModel\Tables\SolarSystemModelTableContext;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Browser\Context\BrowserContext;
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
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\Library\ModerationRulesLibraryAgentDaemon;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationAgentDaemon;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosUserPhotoBrowserTable;

/**
 * Solar System Model project bindings for the framework account and administration features.
 *
 * @property-read SolarSystemModelDbContext $db Database context
 * @property-read EnvAccessor $env Environment accessor
 * @property-read SettingsAccessor $setting Settings accessor
 * @property-read SolarSystemModelRtContext $rt Runtime context
 * @property-read SolarSystemModelTableContext $table Table context
 * @property-read SolarSystemModelBrowserContext $browser Browser context
 */
class Hilos extends HilosFacade
{
    /** @var array<string, list<TruthSourceOperation>> Fixture user writes through the test-only command runner. */
    public const array OWNS_DB = [SolarSystemModelDbContext::users => TruthSourceOperation::BY_KIND];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        TermsHistoryPage::PAGE => TermsHistoryPage::class,
        PrivacyHistoryPage::PAGE => PrivacyHistoryPage::class,
        LicensePage::PAGE => LicensePage::class,
        LicenseDependenciesPage::PAGE => LicenseDependenciesPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        BrowserSessionsPage::PAGE => BrowserSessionsPage::class,
        BrowserSessionPage::PAGE => BrowserSessionPage::class,
        PeoplePage::PAGE => PeoplePage::class,
        PersonPage::PAGE => PersonPage::class,
        CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
        ProfilePage::PAGE => ProfilePage::class,
        ProfileSignInPage::PAGE => ProfileSignInPage::class,
        ProfileSecurityPage::PAGE => ProfileSecurityPage::class,
        ProfileDataPage::PAGE => ProfileDataPage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        SolarSystemModelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SolarSystemModelAgent::class,
            AgentRegistryKey::DAEMON => SolarSystemModelAgentDaemon::class,
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
        SettingsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SettingsLibraryAgent::class,
            AgentRegistryKey::DAEMON => SettingsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        NotificationsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => NotificationsLibraryAgent::class,
            AgentRegistryKey::DAEMON => NotificationsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        SessionsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SessionsLibraryAgent::class,
            AgentRegistryKey::DAEMON => SessionsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        UsersLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => UsersLibraryAgent::class,
            AgentRegistryKey::DAEMON => UsersLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        UserAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => UserAgent::class,
            AgentRegistryKey::DAEMON => UserAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
            AgentRegistryKey::IDLE_TIMEOUT => AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC,
        ],
        DataExportAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DataExportAgent::class,
            AgentRegistryKey::DAEMON => DataExportAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        MailDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => MailDeliveryChannelAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
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
        AuthThrottleAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AuthThrottleAgent::class,
            AgentRegistryKey::DAEMON => AuthThrottleAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        OAuthAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => OAuthAgent::class,
            AgentRegistryKey::DAEMON => OAuthAgentDaemon::class,
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
        SolarSystemModelTableContext::settings => HilosSettingsTable::class,
        SolarSystemModelTableContext::hilosUsers => HilosUsersTable::class,
        SolarSystemModelTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
        SolarSystemModelTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
        SolarSystemModelTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
        SolarSystemModelTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
        SolarSystemModelTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
        SolarSystemModelTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
        SolarSystemModelTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
        HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::class,
    ];

    public const array BROWSER_LISTS = [
        HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::class,
    ];

    public const array PAGE_LISTS = [
        ProfilePage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileSignInPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileSecurityPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileDataPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
    ];

    public const array PAGE_TABLES = [
        TermsHistoryPage::PAGE => [
            SolarSystemModelTableContext::hilosTermsHistory => [],
        ],
        PrivacyHistoryPage::PAGE => [
            SolarSystemModelTableContext::hilosPrivacyHistory => [],
        ],
        SettingsPage::PAGE => [
            SolarSystemModelTableContext::settings => [],
        ],
        BrowserSessionsPage::PAGE => [
            SolarSystemModelTableContext::hilosAnalyticsBrowserSessionList => [],
        ],
        BrowserSessionPage::PAGE => [
            SolarSystemModelTableContext::hilosAnalyticsActions => [],
        ],
        PeoplePage::PAGE => [
            SolarSystemModelTableContext::hilosAnalyticsPeople => [],
        ],
        PersonPage::PAGE => [
            SolarSystemModelTableContext::hilosAnalyticsBrowserSessions => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            SolarSystemModelTableContext::hilosNotificationDeliveries => [],
        ],
        UsersPage::PAGE => [
            SolarSystemModelTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
        ],
    ];

    protected const string ENV_CATALOG = SolarSystemModelEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = SolarSystemModelAuthMethodDirectory::class;

    protected const ?string LEGAL_CATALOG = SolarSystemModelLegalCatalog::class;

    protected const string SETTINGS_CATALOG = SolarSystemModelSettingsCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = SolarSystemModelDeliveryChannelRegistry::class;

    protected const string OAUTH_PROVIDER_DIRECTORY = SolarSystemModelOAuthProviderDirectory::class;

    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::HILOS_USERS,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
        HilosFeature::FILES,
        HilosFeature::UPLOADS,
        HilosFeature::IMAGES,
        HilosFeature::PROFILE_PHOTO,
        HilosFeature::MODERATION,
        HilosFeature::ANALYTICS,
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
     * Creates the Solar System Model database context.
     *
     * @return SolarSystemModelDbContext Solar System Model database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new SolarSystemModelDbContext();
    }

    /**
     * Creates the Solar System Model runtime context.
     *
     * @return ?SolarSystemModelRtContext Solar System Model runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new SolarSystemModelRtContext();
    }

    /**
     * Creates the Solar System Model table context.
     *
     * @return ?SolarSystemModelTableContext Solar System Model table context
     */
    protected static function createTable(): ?TableContext
    {
        return new SolarSystemModelTableContext();
    }

    /**
     * Creates the Solar System Model browser-facing context.
     *
     * @return ?SolarSystemModelBrowserContext Solar System Model browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new SolarSystemModelBrowserContext();
    }

    /**
     * @return ?SolarSystemModelFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new SolarSystemModelFsContext();
    }
}
