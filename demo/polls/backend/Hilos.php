<?php

declare(strict_types=1);

namespace Demo\Polls;

use Demo\Polls\Agents\Hilos\DemoHilosAgent;
use Demo\Polls\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Polls\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Polls\Agents\Hilos\SessionsLibraryAgent;
use Demo\Polls\Agents\Hilos\UsersLibraryAgent;
use Demo\Polls\Agents\OAuthAgent;
use Demo\Polls\Agents\PollsAgent;
use Demo\Polls\Auth\PollsAuthMethodDirectory;
use Demo\Polls\Auth\PollsCodeChannelRegistry;
use Demo\Polls\Auth\PollsOAuthProviderDirectory;
use Demo\Polls\Browser\PollsBrowserContext;
use Demo\Polls\Browser\PollsBrowserRef;
use Demo\Polls\Browser\Table\UserDetailBrowserTable;
use Demo\Polls\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\Polls\Core\Agent\Daemon\PollsAgentDaemon;
use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Database\Settings\PollsSettingsCatalog;
use Demo\Polls\Environment\PollsEnvCatalog;
use Demo\Polls\Legal\PollsLegalCatalog;
use Demo\Polls\Pages\Hilos\AboutPage;
use Demo\Polls\Pages\Hilos\DashboardPage;
use Demo\Polls\Pages\Hilos\LicensePage;
use Demo\Polls\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Polls\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Polls\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\Polls\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\Polls\Pages\Hilos\Logs\LogsViewPage;
use Demo\Polls\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\Polls\Pages\Hilos\PrivacyPage;
use Demo\Polls\Pages\Hilos\SettingsPage;
use Demo\Polls\Pages\Hilos\TermsPage;
use Demo\Polls\Groups\Hilos\NotificationsGroup;
use Demo\Polls\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Polls\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Polls\Pages\Hilos\Security\SecuritySignInMethodsPage;
use Demo\Polls\Pages\Hilos\Security\SecurityPage;
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
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\TruthSource\SharedOwnersKey;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
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
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgentDaemon;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Settings\HilosSettingsTable;

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

    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::HILOS_USERS,
        HilosFeature::LOGS,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::CODE_CHANNELS,
    ];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        LogsOverviewPage::PAGE => LogsOverviewPage::class,
        LogsKeysPage::PAGE => LogsKeysPage::class,
        LogsWorkersPage::PAGE => LogsWorkersPage::class,
        LogsRotationsPage::PAGE => LogsRotationsPage::class,
        LogsViewPage::PAGE => LogsViewPage::class,
        LogsSettingsPage::PAGE => LogsSettingsPage::class,
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicensePage::PAGE => LicensePage::class,
        SecurityPage::PAGE => SecurityPage::class,
        SecurityTwoFactorPage::PAGE => SecurityTwoFactorPage::class,
        SecurityOAuthPage::PAGE => SecurityOAuthPage::class,
        SecurityOAuthProviderPage::PAGE => SecurityOAuthProviderPage::class,
        SecuritySignInMethodsPage::PAGE => SecuritySignInMethodsPage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
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
        UsersLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => UsersLibraryAgent::class,
            AgentRegistryKey::DAEMON => UsersLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
        ],
        DemoHilosLogsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosLogsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosLogsAgentDaemon::class,
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
     * the feature on. Parting them is somebody else's work and it has an address: HIL-630 gives
     * the person an agent of their own, and the auth libraries are parted with it.
     */
    public const array SHARED_DB_OWNERS = [
        PollsDbContext::users => [
            SharedOwnersKey::OWNERS => [PollsAgent::class, SessionsLibraryAgent::class, UsersLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-630',
        ],
        HilosDbContext::identities => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, OAuthAgent::class],
            SharedOwnersKey::DEBT => 'HIL-630',
        ],
        HilosDbContext::sessions => [
            SharedOwnersKey::OWNERS => [SessionsLibraryAgent::class, AuthCodeAgent::class],
            SharedOwnersKey::DEBT => 'HIL-630',
        ],
        HilosDbContext::verifications => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, AuthCodeAgent::class],
            SharedOwnersKey::DEBT => 'HIL-630',
        ],
        HilosDbContext::registrationReservations => [
            SharedOwnersKey::OWNERS => [SessionsLibraryAgent::class, UsersLibraryAgent::class, AuthCodeAgent::class],
            SharedOwnersKey::DEBT => 'HIL-630',
        ],
    ];

    public const array TABLES = [
        PollsTableContext::settings => HilosSettingsTable::class,
        PollsTableContext::hilosUsers => HilosUsersTable::class,
        PollsTableContext::hilosLogKeys => HilosLogKeysTable::class,
        PollsTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        PollsTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        PollsTableContext::hilosSecurityOauthProviders => HilosSecurityOAuthProvidersTable::class,
        PollsTableContext::hilosSecurityOauthProviderFields => HilosSecurityOAuthProviderFieldsTable::class,
        PollsTableContext::hilosSecurityOauthRedirect => HilosSecurityOAuthRedirectTable::class,
        PollsTableContext::hilosSecuritySignInMethods => HilosSecuritySignInMethodsTable::class,
    ];

    public const array BROWSER_TABLES = [
        UserDetailBrowserTable::TABLE => UserDetailBrowserTable::class,
    ];

    public const array PAGE_TABLES = [
        SettingsPage::PAGE => [
            PollsTableContext::settings => [],
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
        UsersPage::PAGE => [
            PollsTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            UserDetailBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    HilosPageRouteParams::HILOS_USER_USER_ID => PollsBrowserRef::HILOS_USER_ID,
                ],
            ],
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
}
