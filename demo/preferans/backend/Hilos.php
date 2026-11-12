<?php

declare(strict_types=1);

namespace Demo\Preferans;

use Demo\Preferans\Agents\PreferansAgent;
use Demo\Preferans\Agents\Hilos\DataExportAgent;
use Demo\Preferans\Agents\Hilos\DemoHilosAgent;
use Demo\Preferans\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Preferans\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Preferans\Agents\Hilos\SessionsLibraryAgent;
use Demo\Preferans\Agents\Hilos\UserAgent;
use Demo\Preferans\Agents\Hilos\UsersLibraryAgent;
use Demo\Preferans\Auth\PreferansAuthMethodDirectory;
use Demo\Preferans\Browser\PreferansBrowserContext;
use Demo\Preferans\Core\Agent\Daemon\PreferansAgentDaemon;
use Demo\Preferans\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Preferans\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\Preferans\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\Preferans\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\Preferans\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\Preferans\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\Preferans\Database\PreferansDbContext;
use Demo\Preferans\Database\Pages\PageCatalog;
use Demo\Preferans\Database\Settings\PreferansSettingsCatalog;
use Demo\Preferans\Environment\PreferansEnvCatalog;
use Demo\Preferans\Fs\PreferansFsContext;
use Demo\Preferans\Groups\Hilos\NotificationsGroup;
use Demo\Preferans\Legal\PreferansLegalCatalog;
use Demo\Preferans\Notification\PreferansDeliveryChannelRegistry;
use Demo\Preferans\Pages\Hilos\AboutPage;
use Demo\Preferans\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\Preferans\Pages\Hilos\DashboardPage;
use Demo\Preferans\Pages\Hilos\AnalyticsPage;
use Demo\Preferans\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\Preferans\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\Preferans\Pages\Hilos\Analytics\PeoplePage;
use Demo\Preferans\Pages\Hilos\Analytics\PersonPage;
use Demo\Preferans\Pages\Hilos\LicensePage;
use Demo\Preferans\Pages\Hilos\LicenseDependenciesPage;
use Demo\Preferans\Pages\Hilos\PrivacyPage;
use Demo\Preferans\Pages\Hilos\PrivacyHistoryPage;
use Demo\Preferans\Pages\Hilos\ProfileNotificationsPage;
use Demo\Preferans\Pages\Hilos\ProfilePage;
use Demo\Preferans\Pages\Hilos\AppearancePage;
use Demo\Preferans\Pages\Hilos\SettingsPage;
use Demo\Preferans\Pages\Hilos\I18nPage;
use Demo\Preferans\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Preferans\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Preferans\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Preferans\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Preferans\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Preferans\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\Preferans\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Preferans\Pages\Hilos\TermsPage;
use Demo\Preferans\Pages\Hilos\TermsHistoryPage;
use Demo\Preferans\Pages\Hilos\Users\UserPage;
use Demo\Preferans\Pages\Hilos\Users\UsersPage;
use Demo\Preferans\Pages\MainPage;
use Demo\Preferans\Runtime\View\Context\PreferansRtContext;
use Demo\Preferans\Tables\HilosUser\HilosUsersTable;
use Demo\Preferans\Tables\PreferansTableContext;
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
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use Hilos\Hilos as HilosFacade;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;

/**
 * Hilos - Main app facade for data access.
 *
 * Password authentication, account pages, settings, i18n, Analytics and email notifications.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read PreferansDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read PreferansRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read PreferansTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read PreferansBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
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
        CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
        ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
        ProfilePage::PAGE => ProfilePage::class,
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
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
        PreferansAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => PreferansAgent::class,
            AgentRegistryKey::DAEMON => PreferansAgentDaemon::class,
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
        HilosDbContext::secondFactorBackupCodes => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactorResets => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactorSettings => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactorTrusts => ['HIL-1907', 'HIL-1908', 'HIL-1909', 'HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactors => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::stepUps => ['HIL-1952'],
        HilosDbContext::userPhotos => ['HIL-1952'],
        HilosDbContext::userRenames => ['HIL-1952'],
        HilosDbContext::users => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::verifications => ['HIL-1952'],
    ];

    public const array TABLES = [
        PreferansTableContext::settings => HilosSettingsTable::class,
        PreferansTableContext::hilosAppearanceSettings => HilosAppearanceSettingsTable::class,
        PreferansTableContext::hilosUsers => HilosUsersTable::class,
        PreferansTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
        PreferansTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
        PreferansTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
        PreferansTableContext::hilosI18nLanguageNames => HilosI18nLanguageNamesTable::class,
        PreferansTableContext::hilosI18nCountryNames => HilosI18nCountryNamesTable::class,
        PreferansTableContext::hilosI18nLanguageLocales => HilosI18nLanguageLocalesTable::class,
        PreferansTableContext::hilosI18nLanguages => HilosI18nLanguagesTable::class,
        PreferansTableContext::hilosI18nCountries => HilosI18nCountriesTable::class,
        PreferansTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
        PreferansTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
        PreferansTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
        PreferansTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
    ];

    public const array BROWSER_LISTS = [
        HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
    ];

    public const array BROWSER_DATA = [
        LanguageCardBrowserData::DATA => LanguageCardBrowserData::class,
        CountryCardBrowserData::DATA => CountryCardBrowserData::class,
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
        BrowserSessionsPage::PAGE => [
            PreferansTableContext::hilosAnalyticsBrowserSessionList => [],
        ],
        BrowserSessionPage::PAGE => [
            PreferansTableContext::hilosAnalyticsActions => [],
        ],
        PeoplePage::PAGE => [
            PreferansTableContext::hilosAnalyticsPeople => [],
        ],
        PersonPage::PAGE => [
            PreferansTableContext::hilosAnalyticsBrowserSessions => [],
        ],
        SettingsPage::PAGE => [
            PreferansTableContext::settings => [],
        ],
        AppearancePage::PAGE => [
            PreferansTableContext::hilosAppearanceSettings => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            PreferansTableContext::hilosNotificationDeliveries => [],
        ],
        UsersPage::PAGE => [
            PreferansTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
        ],
        LanguagesListPage::PAGE => [
            PreferansTableContext::hilosI18nLanguages => [],
        ],
        CountriesListPage::PAGE => [
            PreferansTableContext::hilosI18nCountries => [],
        ],
        LanguageNamesPage::PAGE => [
            PreferansTableContext::hilosI18nLanguageNames => [],
        ],
        LanguageLocalesPage::PAGE => [
            PreferansTableContext::hilosI18nLanguageLocales => [],
        ],
        CountryNamesPage::PAGE => [
            PreferansTableContext::hilosI18nCountryNames => [],
        ],
        TermsHistoryPage::PAGE => [
            PreferansTableContext::hilosTermsHistory => [],
        ],
        PrivacyHistoryPage::PAGE => [
            PreferansTableContext::hilosPrivacyHistory => [],
        ],
    ];

    protected const string ENV_CATALOG = PreferansEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = PreferansAuthMethodDirectory::class;

    protected const string SETTINGS_CATALOG = PreferansSettingsCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = PreferansDeliveryChannelRegistry::class;

    protected const ?string LEGAL_CATALOG = PreferansLegalCatalog::class;

    protected const array FEATURES = [
        HilosFeature::AUTH,
        HilosFeature::SETTINGS,
        HilosFeature::I18N,
        HilosFeature::ANALYTICS,
        HilosFeature::HILOS_USERS,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    /**
     * Creates the preferans database context.
     *
     * @return PreferansDbContext Preferans database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new PreferansDbContext();
    }

    /**
     * Creates the preferans runtime context.
     *
     * @return ?PreferansRtContext Preferans runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new PreferansRtContext();
    }

    /**
     * Creates the preferans table context.
     *
     * @return ?PreferansTableContext Preferans table context
     */
    protected static function createTable(): ?TableContext
    {
        return new PreferansTableContext();
    }

    /**
     * Creates the preferans browser-facing context.
     *
     * @return ?PreferansBrowserContext Preferans browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new PreferansBrowserContext();
    }

    /**
     * @return ?PreferansFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new PreferansFsContext();
    }
}
