<?php

declare(strict_types=1);

namespace Demo\Pmd;

use Demo\Pmd\Agents\Hilos\DataExportAgent;
use Demo\Pmd\Agents\Hilos\DemoHilosAgent;
use Demo\Pmd\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Pmd\Agents\Hilos\FilesLibraryAgent;
use Demo\Pmd\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Pmd\Agents\Hilos\SessionsLibraryAgent;
use Demo\Pmd\Agents\Hilos\UserAgent;
use Demo\Pmd\Agents\Hilos\UsersLibraryAgent;
use Demo\Pmd\Agents\OAuthAgent;
use Demo\Pmd\Agents\PmdAgent;
use Demo\Pmd\Auth\PmdAuthMethodDirectory;
use Demo\Pmd\Auth\PmdOAuthProviderDirectory;
use Demo\Pmd\Browser\Data\SelfConnectionBrowserData;
use Demo\Pmd\Browser\List\ProfileDevicesBrowserList;
use Demo\Pmd\Browser\List\ProfileSessionsBrowserList;
use Demo\Pmd\Browser\PmdBrowserContext;
use Demo\Pmd\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Pmd\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\Pmd\Core\Agent\Daemon\Hilos\FilesLibraryAgentDaemon;
use Demo\Pmd\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\Pmd\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\Pmd\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\Pmd\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\Pmd\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\Pmd\Core\Agent\Daemon\PmdAgentDaemon;
use Demo\Pmd\Database\Pages\PageCatalog;
use Demo\Pmd\Database\PmdDbContext;
use Demo\Pmd\Database\Settings\PmdSettingsCatalog;
use Demo\Pmd\Environment\PmdEnvCatalog;
use Demo\Pmd\Fs\PmdFsContext;
use Demo\Pmd\Groups\Hilos\NotificationsGroup;
use Demo\Pmd\Legal\PmdLegalCatalog;
use Demo\Pmd\Notification\PmdDeliveryChannelRegistry;
use Demo\Pmd\Pages\Hilos\AboutPage;
use Demo\Pmd\Pages\Hilos\AnalyticsPage;
use Demo\Pmd\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\Pmd\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\Pmd\Pages\Hilos\Analytics\PeoplePage;
use Demo\Pmd\Pages\Hilos\Analytics\PersonPage;
use Demo\Pmd\Pages\Hilos\AppearancePage;
use Demo\Pmd\Pages\Hilos\Communications\CommunicationsChannelConfigurationPage;
use Demo\Pmd\Pages\Hilos\Communications\CommunicationsChannelFramePage;
use Demo\Pmd\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\Pmd\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\Pmd\Pages\Hilos\Communications\CommunicationsPage;
use Demo\Pmd\Pages\Hilos\DashboardPage;
use Demo\Pmd\Pages\Hilos\LicenseDependenciesPage;
use Demo\Pmd\Pages\Hilos\LicensePage;
use Demo\Pmd\Pages\Hilos\PrivacyHistoryPage;
use Demo\Pmd\Pages\Hilos\PrivacyPage;
use Demo\Pmd\Pages\Hilos\ProfileAgreementsPage;
use Demo\Pmd\Pages\Hilos\ProfileDataPage;
use Demo\Pmd\Pages\Hilos\ProfileDevicesPage;
use Demo\Pmd\Pages\Hilos\ProfileNotificationsPage;
use Demo\Pmd\Pages\Hilos\ProfilePage;
use Demo\Pmd\Pages\Hilos\ProfileSecurityPage;
use Demo\Pmd\Pages\Hilos\ProfileSessionsPage;
use Demo\Pmd\Pages\Hilos\ProfileSignInPage;
use Demo\Pmd\Pages\Hilos\Security\SecurityImpersonationPage;
use Demo\Pmd\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Pmd\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Pmd\Pages\Hilos\Security\SecurityPage;
use Demo\Pmd\Pages\Hilos\Security\SecuritySignInMethodsPage;
use Demo\Pmd\Pages\Hilos\Security\SecurityStepUpPage;
use Demo\Pmd\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Pmd\Pages\Hilos\SettingsPage;
use Demo\Pmd\Pages\Hilos\TermsHistoryPage;
use Demo\Pmd\Pages\Hilos\TermsPage;
use Demo\Pmd\Pages\Hilos\Users\UserPage;
use Demo\Pmd\Pages\Hilos\Users\UserSessionsPage;
use Demo\Pmd\Pages\Hilos\Users\UsersPage;
use Demo\Pmd\Pages\MainPage;
use Demo\Pmd\Runtime\View\Context\PmdRtContext;
use Demo\Pmd\Tables\HilosUser\HilosUsersTable;
use Demo\Pmd\Tables\PmdTableContext;
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
use Hilos\Pages\Users\HilosUserActingSessionsBrowserList;
use Hilos\Pages\Users\HilosUserSessionsBrowserList;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Security\HilosSecurityImpersonationTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecurityStepUpTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosUserPhotoBrowserTable;

/**
 * PMD project bindings for the framework account and administration features.
 *
 * @property-read PmdDbContext $db Database context
 * @property-read EnvAccessor $env Environment accessor
 * @property-read SettingsAccessor $setting Settings accessor
 * @property-read PmdRtContext $rt Runtime context
 * @property-read PmdTableContext $table Table context
 * @property-read PmdBrowserContext $browser Browser context
 */
class Hilos extends HilosFacade
{
    /** @var array<string, list<TruthSourceOperation>> Fixture user writes through the test-only command runner. */
    public const array OWNS_DB = [PmdDbContext::users => TruthSourceOperation::BY_KIND];

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
        AppearancePage::PAGE => AppearancePage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
        BrowserSessionsPage::PAGE => BrowserSessionsPage::class,
        BrowserSessionPage::PAGE => BrowserSessionPage::class,
        PeoplePage::PAGE => PeoplePage::class,
        PersonPage::PAGE => PersonPage::class,
        CommunicationsPage::PAGE => CommunicationsPage::class,
        CommunicationsChannelPage::PAGE => CommunicationsChannelPage::class,
        CommunicationsChannelConfigurationPage::PAGE => CommunicationsChannelConfigurationPage::class,
        CommunicationsChannelFramePage::PAGE => CommunicationsChannelFramePage::class,
        CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
        UserSessionsPage::PAGE => UserSessionsPage::class,
        ProfilePage::PAGE => ProfilePage::class,
        ProfileSignInPage::PAGE => ProfileSignInPage::class,
        ProfileSecurityPage::PAGE => ProfileSecurityPage::class,
        ProfileSessionsPage::PAGE => ProfileSessionsPage::class,
        ProfileDevicesPage::PAGE => ProfileDevicesPage::class,
        ProfileDataPage::PAGE => ProfileDataPage::class,
        ProfileAgreementsPage::PAGE => ProfileAgreementsPage::class,
        SecurityPage::PAGE => SecurityPage::class,
        SecuritySignInMethodsPage::PAGE => SecuritySignInMethodsPage::class,
        SecurityOAuthPage::PAGE => SecurityOAuthPage::class,
        SecurityOAuthProviderPage::PAGE => SecurityOAuthProviderPage::class,
        SecurityStepUpPage::PAGE => SecurityStepUpPage::class,
        SecurityTwoFactorPage::PAGE => SecurityTwoFactorPage::class,
        SecurityImpersonationPage::PAGE => SecurityImpersonationPage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        PmdAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => PmdAgent::class,
            AgentRegistryKey::DAEMON => PmdAgentDaemon::class,
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
        PmdTableContext::settings => HilosSettingsTable::class,
        PmdTableContext::hilosAppearanceSettings => HilosAppearanceSettingsTable::class,
        PmdTableContext::hilosUsers => HilosUsersTable::class,
        PmdTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
        PmdTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
        PmdTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
        PmdTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
        PmdTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
        PmdTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
        PmdTableContext::hilosCommunicationsChannels => HilosCommunicationsChannelsTable::class,
        PmdTableContext::hilosCommunicationsChannelFields => HilosCommunicationsChannelFieldsTable::class,
        PmdTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
        PmdTableContext::hilosSecurityOauthProviders => HilosSecurityOAuthProvidersTable::class,
        PmdTableContext::hilosSecurityOauthProviderFields => HilosSecurityOAuthProviderFieldsTable::class,
        PmdTableContext::hilosSecurityOauthRedirect => HilosSecurityOAuthRedirectTable::class,
        PmdTableContext::hilosSecuritySignInMethods => HilosSecuritySignInMethodsTable::class,
        PmdTableContext::hilosSecurityTwoFactor => HilosSecurityTwoFactorTable::class,
        PmdTableContext::hilosSecurityStepUp => HilosSecurityStepUpTable::class,
        PmdTableContext::hilosSecurityImpersonation => HilosSecurityImpersonationTable::class,
        PmdTableContext::hilosMergeCandidates => HilosMergeCandidatesTable::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
        HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::class,
    ];

    public const array BROWSER_LISTS = [
        HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::class,
        HilosUserSessionsBrowserList::LIST => HilosUserSessionsBrowserList::class,
        HilosUserActingSessionsBrowserList::LIST => HilosUserActingSessionsBrowserList::class,
        ProfileSessionsBrowserList::LIST => ProfileSessionsBrowserList::class,
        ProfileDevicesBrowserList::LIST => ProfileDevicesBrowserList::class,
    ];

    public const array BROWSER_DATA = [
        SelfConnectionBrowserData::DATA => SelfConnectionBrowserData::class,
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
        ProfileSessionsPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
            ProfileSessionsBrowserList::LIST => ProfileSessionsBrowserList::BINDING,
        ],
        ProfileDevicesPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
            ProfileDevicesBrowserList::LIST => ProfileDevicesBrowserList::BINDING,
        ],
        ProfileDataPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileAgreementsPage::PAGE => [
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
        ProfileSessionsPage::PAGE => [
            SelfConnectionBrowserData::DATA => SelfConnectionBrowserData::BINDING,
        ],
    ];

    public const array PAGE_TABLES = [
        TermsHistoryPage::PAGE => [
            PmdTableContext::hilosTermsHistory => [],
        ],
        PrivacyHistoryPage::PAGE => [
            PmdTableContext::hilosPrivacyHistory => [],
        ],
        SettingsPage::PAGE => [
            PmdTableContext::settings => [],
        ],
        AppearancePage::PAGE => [
            PmdTableContext::hilosAppearanceSettings => [],
        ],
        BrowserSessionsPage::PAGE => [
            PmdTableContext::hilosAnalyticsBrowserSessionList => [],
        ],
        BrowserSessionPage::PAGE => [
            PmdTableContext::hilosAnalyticsActions => [],
        ],
        PeoplePage::PAGE => [
            PmdTableContext::hilosAnalyticsPeople => [],
        ],
        PersonPage::PAGE => [
            PmdTableContext::hilosAnalyticsBrowserSessions => [],
        ],
        CommunicationsPage::PAGE => [
            PmdTableContext::hilosCommunicationsChannels => [],
        ],
        CommunicationsChannelConfigurationPage::PAGE => [
            PmdTableContext::hilosCommunicationsChannelFields => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            PmdTableContext::hilosNotificationDeliveries => [],
        ],
        UsersPage::PAGE => [
            PmdTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
            PmdTableContext::hilosMergeCandidates => [],
        ],
        UserSessionsPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
        ],
        SecuritySignInMethodsPage::PAGE => [
            PmdTableContext::hilosSecuritySignInMethods => [],
        ],
        SecurityOAuthPage::PAGE => [
            PmdTableContext::hilosSecurityOauthRedirect => [],
            PmdTableContext::hilosSecurityOauthProviders => [],
        ],
        SecurityOAuthProviderPage::PAGE => [
            PmdTableContext::hilosSecurityOauthProviders => [],
            PmdTableContext::hilosSecurityOauthProviderFields => [],
        ],
        SecurityStepUpPage::PAGE => [
            PmdTableContext::hilosSecurityStepUp => [],
        ],
        SecurityTwoFactorPage::PAGE => [
            PmdTableContext::hilosSecurityTwoFactor => [],
        ],
        SecurityImpersonationPage::PAGE => [
            PmdTableContext::hilosSecurityImpersonation => [],
        ],
    ];

    protected const string ENV_CATALOG = PmdEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = PmdAuthMethodDirectory::class;

    protected const ?string LEGAL_CATALOG = PmdLegalCatalog::class;

    protected const string SETTINGS_CATALOG = PmdSettingsCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = PmdDeliveryChannelRegistry::class;

    protected const string OAUTH_PROVIDER_DIRECTORY = PmdOAuthProviderDirectory::class;

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
     * Creates the pmd database context.
     *
     * @return PmdDbContext PMD database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new PmdDbContext();
    }

    /**
     * Creates the pmd runtime context.
     *
     * @return ?PmdRtContext PMD runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new PmdRtContext();
    }

    /**
     * Creates the pmd table context.
     *
     * @return ?PmdTableContext PMD table context
     */
    protected static function createTable(): ?TableContext
    {
        return new PmdTableContext();
    }

    /**
     * Creates the pmd browser-facing context.
     *
     * @return ?PmdBrowserContext PMD browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new PmdBrowserContext();
    }

    /**
     * @return ?PmdFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new PmdFsContext();
    }
}
