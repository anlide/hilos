<?php

declare(strict_types=1);

namespace Demo\Polls;

use Demo\Polls\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Polls\Core\Agent\Daemon\Hilos\DemoHilosLegalAgentDaemon;
use Demo\Polls\Pages\Hilos\Legal\LegalPage;
use Demo\Polls\Pages\Hilos\Legal\LegalDocumentPage;
use Demo\Polls\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Polls\Pages\Hilos\Legal\LegalAcceptancesPage;
use Demo\Polls\Pages\Hilos\Legal\LegalSettingsPage;
use Demo\Polls\Tables\HilosLegal\HilosLegalAcceptancesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;

use Demo\Polls\Agents\Hilos\DataExportAgent;
use Demo\Polls\Agents\Hilos\DemoHilosAgent;
use Demo\Polls\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\Polls\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Polls\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Polls\Agents\Hilos\SessionsLibraryAgent;
use Demo\Polls\Agents\Hilos\UserAgent;
use Demo\Polls\Agents\Hilos\UsersLibraryAgent;
use Demo\Polls\Agents\OAuthAgent;
use Demo\Polls\Agents\PollsAgent;
use Demo\Polls\Auth\PollsAuthMethodDirectory;
use Demo\Polls\Auth\PollsCodeChannelRegistry;
use Demo\Polls\Auth\PollsOAuthProviderDirectory;
use Demo\Polls\Browser\PollsBrowserContext;
use Demo\Polls\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\PollsAgentDaemon;
use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Database\Settings\PollsSettingsCatalog;
use Demo\Polls\Environment\PollsEnvCatalog;
use Demo\Polls\Fs\PollsFsContext;
use Demo\Polls\Legal\PollsLegalCatalog;
use Demo\Polls\Pages\Hilos\AboutPage;
use Demo\Polls\Pages\Hilos\DashboardPage;
use Demo\Polls\Pages\Hilos\LicensePage;
use Demo\Polls\Pages\Hilos\Daemon\DaemonPage;
use Demo\Polls\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\Polls\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\Polls\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\Polls\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\Polls\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\Polls\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\Polls\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\Polls\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Polls\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Polls\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\Polls\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\Polls\Pages\Hilos\Logs\LogsViewPage;
use Demo\Polls\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\Polls\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\Polls\Pages\Hilos\PrivacyPage;
use Demo\Polls\Pages\Hilos\SettingsPage;
use Demo\Polls\Pages\Hilos\I18nPage;
use Demo\Polls\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Polls\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Polls\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Polls\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Polls\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Polls\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\Polls\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Polls\Pages\Hilos\TermsPage;
use Demo\Polls\Groups\Hilos\NotificationsGroup;
use Demo\Polls\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Polls\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Polls\Pages\Hilos\Security\SecurityImpersonationPage;
use Demo\Polls\Pages\Hilos\Security\SecuritySignInMethodsPage;
use Demo\Polls\Pages\Hilos\Security\SecurityPage;
use Demo\Polls\Pages\Hilos\ProfilePage;
use Demo\Polls\Pages\Hilos\ProfileSecurityPage;
use Demo\Polls\Pages\Hilos\ProfileDataPage;
use Demo\Polls\Pages\Hilos\Security\SecurityStepUpPage;
use Demo\Polls\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Polls\Pages\Hilos\Users\UserPage;
use Demo\Polls\Pages\Hilos\Users\UsersPage;
use Demo\Polls\Pages\MainPage;
use Demo\Polls\Runtime\View\Context\PollsRtContext;
use Demo\Polls\Tables\HilosUser\HilosUsersTable;
use Demo\Polls\Tables\PollsTableContext;
use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\AuthCodeAgentDaemon;
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
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Security\HilosSecurityStepUpTable;
use Hilos\Tables\Security\HilosSecurityImpersonationTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;

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
 * @property-read PollsDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read PollsRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read PollsTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read PollsBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = PollsEnvCatalog::class;

    protected const string SETTINGS_CATALOG = PollsSettingsCatalog::class;

    protected const string CODE_CHANNEL_REGISTRY = PollsCodeChannelRegistry::class;

    protected const string AUTH_METHOD_DIRECTORY = PollsAuthMethodDirectory::class;

    protected const string OAUTH_PROVIDER_DIRECTORY = PollsOAuthProviderDirectory::class;

    protected const ?string LEGAL_CATALOG = PollsLegalCatalog::class;

    // TODO(HIL-1090): switch HilosFeature::BACKUP on in polls once more than six demos are implemented.
    // Until then, its page answers 404 and has no dashboard card.
    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::I18N,
        HilosFeature::HILOS_USERS,
        HilosFeature::LOGS,
        HilosFeature::DAEMON,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::CODE_CHANNELS,
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
        LanguageDetailPage::PAGE => LanguageDetailPage::class,
        LanguageNamesPage::PAGE => LanguageNamesPage::class,
        LanguageLocalesPage::PAGE => LanguageLocalesPage::class,
        CountryDetailPage::PAGE => CountryDetailPage::class,
        CountryNamesPage::PAGE => CountryNamesPage::class,
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
        SecurityPage::PAGE => SecurityPage::class,
        SecurityTwoFactorPage::PAGE => SecurityTwoFactorPage::class,
        SecurityStepUpPage::PAGE => SecurityStepUpPage::class,
        LegalPage::PAGE => LegalPage::class,
        LegalDocumentPage::PAGE => LegalDocumentPage::class,
        LegalRevisionPage::PAGE => LegalRevisionPage::class,
        LegalAcceptancesPage::PAGE => LegalAcceptancesPage::class,
        LegalSettingsPage::PAGE => LegalSettingsPage::class,
        ProfilePage::PAGE => ProfilePage::class,
        ProfileSecurityPage::PAGE => ProfileSecurityPage::class,
        ProfileDataPage::PAGE => ProfileDataPage::class,
        SecurityOAuthPage::PAGE => SecurityOAuthPage::class,
        SecurityOAuthProviderPage::PAGE => SecurityOAuthProviderPage::class,
        SecuritySignInMethodsPage::PAGE => SecuritySignInMethodsPage::class,
        SecurityImpersonationPage::PAGE => SecurityImpersonationPage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        DataExportAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DataExportAgent::class,
            AgentRegistryKey::DAEMON => DataExportAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        PollsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => PollsAgent::class,
            AgentRegistryKey::DAEMON => PollsAgentDaemon::class,
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

    /**
     * The collections this demo still lets two owners hold, and who will part them.
     *
     * Receipts, not permissions: every pair here is a place where two agents write the same rows
     * today, and startup refuses both a pair that is missing from this list and a row whose
     * owners no longer collide. The rights of nobody changed when the list was written - what
     * writes today goes on writing, out loud instead of by eye.
     *
     * Five rows, and all of them are signing in: this demo's people table, held by its own agent
     * together with the sessions and the users library, and the four framework tables those
     * libraries share with the code agent. They stand the same way in every demo that switches
     * the feature on. The rows are parted by their own leaves: users by HIL-1404, identities
     * by HIL-1405, stepUps by HIL-1407, and verifications and registrationReservations by HIL-1411.
     * See docs/agents/architecture/instance-owners.md#where-the-pieces-land.
     */
    public const array SHARED_DB_OWNERS = [
        HilosDbContext::stepUps => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, SessionsLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1407',
        ],
        PollsDbContext::users => [
            SharedOwnersKey::OWNERS => [PollsAgent::class, SessionsLibraryAgent::class, UsersLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1404',
        ],
        HilosDbContext::identities => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, OAuthAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1405',
        ],
        HilosDbContext::verifications => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, AuthCodeAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1411',
        ],
        HilosDbContext::registrationReservations => [
            SharedOwnersKey::OWNERS => [SessionsLibraryAgent::class, UsersLibraryAgent::class, AuthCodeAgent::class],
            SharedOwnersKey::DEBT => 'HIL-1411',
        ],
    ];

    public const array TABLES = [
        PollsTableContext::settings => HilosSettingsTable::class,
        PollsTableContext::hilosUsers => HilosUsersTable::class,
        PollsTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
        PollsTableContext::hilosLogKeys => HilosLogKeysTable::class,
        PollsTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        PollsTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        PollsTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
        PollsTableContext::hilosSecurityOauthProviders => HilosSecurityOAuthProvidersTable::class,
        PollsTableContext::hilosSecurityOauthProviderFields => HilosSecurityOAuthProviderFieldsTable::class,
        PollsTableContext::hilosSecurityOauthRedirect => HilosSecurityOAuthRedirectTable::class,
        PollsTableContext::hilosSecuritySignInMethods => HilosSecuritySignInMethodsTable::class,
        PollsTableContext::hilosSecurityTwoFactor => HilosSecurityTwoFactorTable::class,
        PollsTableContext::hilosSecurityImpersonation => HilosSecurityImpersonationTable::class,
        PollsTableContext::hilosLegalDocuments => HilosLegalDocumentsTable::class,
        PollsTableContext::hilosLegalChecks => HilosLegalChecksTable::class,
        PollsTableContext::hilosLegalRevisions => HilosLegalRevisionsTable::class,
        PollsTableContext::hilosLegalAcceptances => HilosLegalAcceptancesTable::class,
        PollsTableContext::hilosLegalSettings => HilosLegalSettingsTable::class,
        PollsTableContext::hilosSecurityStepUp => HilosSecurityStepUpTable::class,
        PollsTableContext::hilosI18nLanguageNames => HilosI18nLanguageNamesTable::class,
        PollsTableContext::hilosI18nCountryNames => HilosI18nCountryNamesTable::class,
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
    ];

    public const array PAGE_DATA = [
        LanguageDetailPage::PAGE => [
            LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING,
        ],
        CountryDetailPage::PAGE => [
            CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING,
        ],
    ];

    public const array PAGE_TABLES = [
        SettingsPage::PAGE => [
            PollsTableContext::settings => [],
        ],
        MaintenancePage::PAGE => [
            PollsTableContext::hilosVerifierCircle => [],
        ],
        LogsKeysPage::PAGE => [
            PollsTableContext::hilosLogKeys => [],
        ],
        LogsRotationsPage::PAGE => [
            PollsTableContext::hilosLogRotations => [],
        ],
        LogsWorkersPage::PAGE => [
            PollsTableContext::hilosLogWorkers => [],
        ],
        DaemonCronPage::PAGE => [
            PollsTableContext::hilosDaemonCron => [],
        ],
        SecurityOAuthPage::PAGE => [
            PollsTableContext::hilosSecurityOauthRedirect => [],
            PollsTableContext::hilosSecurityOauthProviders => [],
        ],
        SecurityOAuthProviderPage::PAGE => [
            PollsTableContext::hilosSecurityOauthProviders => [],
            PollsTableContext::hilosSecurityOauthProviderFields => [],
        ],
        SecuritySignInMethodsPage::PAGE => [
            PollsTableContext::hilosSecuritySignInMethods => [],
        ],
        SecurityTwoFactorPage::PAGE => [
            PollsTableContext::hilosSecurityTwoFactor => [],
        ],
        SecurityStepUpPage::PAGE => [
            PollsTableContext::hilosSecurityStepUp => [],
        ],
        SecurityImpersonationPage::PAGE => [
            PollsTableContext::hilosSecurityImpersonation => [],
        ],
        LegalPage::PAGE => [
            PollsTableContext::hilosLegalDocuments => [],
            PollsTableContext::hilosLegalChecks => [],
            PollsTableContext::hilosLegalSettings => [],
        ],
        LegalDocumentPage::PAGE => [
            PollsTableContext::hilosLegalRevisions => [],
        ],
        LegalRevisionPage::PAGE => [
            PollsTableContext::hilosLegalRevisions => [],
        ],
        LegalAcceptancesPage::PAGE => [
            PollsTableContext::hilosLegalAcceptances => [],
        ],
        LegalSettingsPage::PAGE => [
            PollsTableContext::hilosLegalSettings => [],
        ],
        UsersPage::PAGE => [
            PollsTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
        ],
        LanguageNamesPage::PAGE => [
            PollsTableContext::hilosI18nLanguageNames => [],
        ],
        CountryNamesPage::PAGE => [
            PollsTableContext::hilosI18nCountryNames => [],
        ],
    ];

    /**
     * Creates the polls database context.
     *
     * @return PollsDbContext Polls database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new PollsDbContext();
    }

    /**
     * Creates the polls runtime context.
     *
     * @return ?PollsRtContext Polls runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new PollsRtContext();
    }

    /**
     * Creates the polls table context.
     *
     * @return ?PollsTableContext Polls table context
     */
    protected static function createTable(): ?TableContext
    {
        return new PollsTableContext();
    }

    /**
     * Creates the polls browser-facing context.
     *
     * @return ?PollsBrowserContext Polls browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new PollsBrowserContext();
    }

    /**
     * @return ?PollsFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new PollsFsContext();
    }
}
