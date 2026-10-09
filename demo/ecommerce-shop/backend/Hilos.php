<?php

declare(strict_types=1);

namespace Demo\EcommerceShop;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\Hilos\DataExportAgent;
use Demo\EcommerceShop\Agents\Hilos\DemoHilosAgent;
use Demo\EcommerceShop\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\EcommerceShop\Agents\Hilos\NotificationsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\SessionsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Agents\Hilos\UsersLibraryAgent;
use Demo\EcommerceShop\Auth\EcommerceShopAuthMethodDirectory;
use Demo\EcommerceShop\Backup\BackupCatalog;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserContext;
use Demo\EcommerceShop\Core\Agent\Daemon\EcommerceShopAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Settings\EcommerceShopSettingsCatalog;
use Demo\EcommerceShop\Environment\EcommerceShopEnvCatalog;
use Demo\EcommerceShop\Fs\EcommerceShopFsContext;
use Demo\EcommerceShop\Groups\Hilos\NotificationsGroup;
use Demo\EcommerceShop\Legal\EcommerceShopLegalCatalog;
use Demo\EcommerceShop\Pages\Hilos\AboutPage;
use Demo\EcommerceShop\Pages\Hilos\Backup\BackupPage;
use Demo\EcommerceShop\Pages\Hilos\DashboardPage;
use Demo\EcommerceShop\Pages\Hilos\AnalyticsPage;
use Demo\EcommerceShop\Pages\Hilos\LicensePage;
use Demo\EcommerceShop\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\EcommerceShop\Pages\Hilos\PrivacyPage;
use Demo\EcommerceShop\Pages\Hilos\SettingsPage;
use Demo\EcommerceShop\Pages\Hilos\I18nPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\EcommerceShop\Pages\Hilos\TermsPage;
use Demo\EcommerceShop\Pages\Hilos\Users\UserPage;
use Demo\EcommerceShop\Pages\Hilos\Users\UsersPage;
use Demo\EcommerceShop\Pages\MainPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Demo\EcommerceShop\Tables\HilosUser\HilosUsersTable;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Cluster\Probe\ClaimerProbeAgent;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Cluster\Probe\FleetProbeAgent;
use Hilos\Constants\HilosAgentType;
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
use Hilos\Core\TruthSource\SharedOwnersKey;
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
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;

/**
 * Hilos - Main app facade for data access.
 *
 * The smallest complete shape of a project: sign-in by password, an empty home, the admin
 * dashboard and the four public footer pages. Five admin sections are activated so far -
 * Maintenance, the verifier circle a freeze lets through, Backup, the database archives,
 * Settings, the three example keys, and Users, the people and a person's card (renaming,
 * takeover, rights; no account merge) - and the others arrive with the leaves that bring them.
 *
 * Notifications are switched on with them: the bell in the header, without delivery.
 *
 * Its cluster stand (docker/docker-compose.cluster.yml) runs the framework's fleet, claimer and
 * ballast probes.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read EcommerceShopDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read EcommerceShopRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read EcommerceShopTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read EcommerceShopBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = EcommerceShopEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = EcommerceShopAuthMethodDirectory::class;

    protected const ?string LEGAL_CATALOG = EcommerceShopLegalCatalog::class;

    protected const ?string BACKUP_CATALOG = BackupCatalog::class;

    protected const string SETTINGS_CATALOG = EcommerceShopSettingsCatalog::class;

    protected const array FEATURES = [
        HilosFeature::ANALYTICS,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::BACKUP,
        HilosFeature::SETTINGS,
        HilosFeature::I18N,
        HilosFeature::HILOS_USERS,
        HilosFeature::NOTIFICATIONS,
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        BackupPage::PAGE => BackupPage::class,
        MaintenancePage::PAGE => MaintenancePage::class,
        SettingsPage::PAGE => SettingsPage::class,
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
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicensePage::PAGE => LicensePage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        EcommerceShopAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => EcommerceShopAgent::class,
            AgentRegistryKey::DAEMON => EcommerceShopAgentDaemon::class,
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
        BackupAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BackupAgent::class,
            AgentRegistryKey::DAEMON => BackupAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        // The probes of this demo's cluster stand - scenarios 3, 4, 9, 12, 14, 16 and 19 read the
        // fleet, 14 stages a second owner of its collection with the claimer, 18 fills the slaves
        // with the ballast. A probe starts only on a clustered node of a non-production
        // environment, so this demo on one node, on its Playwright stand and in production carries
        // the rows and runs none of them (docs/agents/testing.md, "The cluster stands").
        HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
        HilosAgentType::HILOS_PROBE_CLAIMER => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_CLAIMER],
        HilosAgentType::HILOS_PROBE_BALLAST => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_BALLAST],
    ];

    /**
     * The collections this demo still lets two owners hold, and who will part them.
     *
     * Receipts, not permissions: every pair here is a place where two agents write the same rows
     * today, and startup refuses both a pair that is missing from this list and a row whose
     * owners no longer collide.
     *
     * Two rows, both of them signing in: the step-up proofs and the registration holds, each
     * shared by the sessions and the users library. They stand the same way in every demo that
     * switches the feature on; the rows the provider and the code agents add elsewhere do not
     * arise here, because this demo runs neither. The people are no longer among them: an edit of
     * one person is that person's agent's, and the libraries' shares of the row no longer collide
     * (HIL-1404). The rows are parted by their own leaves: stepUps by HIL-1407, and
     * registrationReservations by HIL-1411.
     * See docs/agents/architecture/instance-owners.md#where-the-pieces-land.
     */
    public const array SHARED_DB_OWNERS = [
        HilosDbContext::stepUps => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, SessionsLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1407',
        ],
        HilosDbContext::registrationReservations => [
            SharedOwnersKey::OWNERS => [SessionsLibraryAgent::class, UsersLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1411',
        ],
    ];

    /**
     * The one runtime collection this demo lets two owners hold - on purpose.
     *
     * A receipt, not a permission: the claimer holds the whole status collection while every fleet
     * member holds its own row, and that is the split scenario 14 of its cluster stand exists to
     * exercise. Startup would refuse the pair without this row.
     */
    public const array SHARED_RT_OWNERS = [
        HilosProbeFleetStatus::RT_COLLECTION => [
            SharedOwnersKey::OWNERS => [ClaimerProbeAgent::class, FleetProbeAgent::class],
            SharedOwnersKey::DEBT => 'intentional: the cluster stand stages this split in scenario 14 to exercise the runtime two-owner guard',
        ],
    ];

    public const array TABLES = [
        EcommerceShopTableContext::hilosBackups => HilosBackupHistoryTable::class,
        EcommerceShopTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
        EcommerceShopTableContext::settings => HilosSettingsTable::class,
        EcommerceShopTableContext::hilosUsers => HilosUsersTable::class,
        EcommerceShopTableContext::hilosI18nLanguageNames => HilosI18nLanguageNamesTable::class,
        EcommerceShopTableContext::hilosI18nCountryNames => HilosI18nCountryNamesTable::class,
        EcommerceShopTableContext::hilosI18nLanguageLocales => HilosI18nLanguageLocalesTable::class,
        EcommerceShopTableContext::hilosI18nLanguages => HilosI18nLanguagesTable::class,
    ];

    public const array BROWSER_DATA = [
        LanguageCardBrowserData::DATA => LanguageCardBrowserData::class,
        CountryCardBrowserData::DATA => CountryCardBrowserData::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
    ];

    public const array PAGE_DATA = [
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
        BackupPage::PAGE => [
            EcommerceShopTableContext::hilosBackups => [],
        ],
        MaintenancePage::PAGE => [
            EcommerceShopTableContext::hilosVerifierCircle => [],
        ],
        SettingsPage::PAGE => [
            EcommerceShopTableContext::settings => [],
        ],
        UsersPage::PAGE => [
            EcommerceShopTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
        ],
        LanguagesListPage::PAGE => [
            EcommerceShopTableContext::hilosI18nLanguages => [],
        ],
        LanguageNamesPage::PAGE => [
            EcommerceShopTableContext::hilosI18nLanguageNames => [],
        ],
        LanguageLocalesPage::PAGE => [
            EcommerceShopTableContext::hilosI18nLanguageLocales => [],
        ],
        CountryNamesPage::PAGE => [
            EcommerceShopTableContext::hilosI18nCountryNames => [],
        ],
    ];

    /**
     * Creates the ecommerce-shop database context.
     *
     * @return EcommerceShopDbContext E-commerce shop database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new EcommerceShopDbContext();
    }

    /**
     * Creates the ecommerce-shop runtime context.
     *
     * @return ?EcommerceShopRtContext E-commerce shop runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new EcommerceShopRtContext();
    }

    /**
     * Creates the ecommerce-shop table context.
     *
     * @return ?EcommerceShopTableContext E-commerce shop table context
     */
    protected static function createTable(): ?TableContext
    {
        return new EcommerceShopTableContext();
    }

    /**
     * Creates the ecommerce-shop browser-facing context.
     *
     * @return ?EcommerceShopBrowserContext E-commerce shop browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new EcommerceShopBrowserContext();
    }

    /**
     * @return ?EcommerceShopFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new EcommerceShopFsContext();
    }
}
