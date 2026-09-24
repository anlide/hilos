<?php

declare(strict_types=1);

namespace Demo\Tasks;

use Demo\Tasks\Agents\Hilos\DemoHilosAgent;
use Demo\Tasks\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Tasks\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Tasks\Agents\Hilos\SessionsLibraryAgent;
use Demo\Tasks\Agents\Hilos\UsersLibraryAgent;
use Demo\Tasks\Agents\OAuthAgent;
use Demo\Tasks\Agents\TasksAgent;
use Demo\Tasks\Auth\TasksAuthMethodDirectory;
use Demo\Tasks\Auth\TasksCodeChannelRegistry;
use Demo\Tasks\Auth\TasksOAuthProviderDirectory;
use Demo\Tasks\Backup\BackupCatalog;
use Demo\Tasks\Browser\Table\UserDetailBrowserTable;
use Demo\Tasks\Browser\TasksBrowserContext;
use Demo\Tasks\Browser\TasksBrowserRef;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\TasksAgentDaemon;
use Demo\Tasks\Database\Settings\TasksSettingsCatalog;
use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Environment\TasksEnvCatalog;
use Demo\Tasks\Legal\TasksLegalCatalog;
use Demo\Tasks\Pages\Hilos\AboutPage;
use Demo\Tasks\Pages\Hilos\Backup\BackupPage;
use Demo\Tasks\Pages\Hilos\DashboardPage;
use Demo\Tasks\Pages\Hilos\LicensePage;
use Demo\Tasks\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsViewPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\Tasks\Pages\Hilos\PrivacyPage;
use Demo\Tasks\Pages\Hilos\SettingsPage;
use Demo\Tasks\Pages\Hilos\TermsPage;
use Demo\Tasks\Groups\Hilos\NotificationsGroup;
use Demo\Tasks\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Tasks\Pages\Hilos\Security\SecuritySignInMethodsPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityPage;
use Demo\Tasks\Pages\Hilos\ProfileSecurityPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Tasks\Pages\Hilos\Users\UserPage;
use Demo\Tasks\Pages\Hilos\Users\UsersPage;
use Demo\Tasks\Pages\MainPage;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
use Demo\Tasks\Tables\HilosUser\HilosUsersTable;
use Demo\Tasks\Tables\TasksTableContext;
use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\AuthCodeAgentDaemon;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
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
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
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
 * @property-read TasksDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read TasksRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read TasksTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read TasksBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = TasksEnvCatalog::class;

    protected const string SETTINGS_CATALOG = TasksSettingsCatalog::class;

    protected const string CODE_CHANNEL_REGISTRY = TasksCodeChannelRegistry::class;

    protected const string AUTH_METHOD_DIRECTORY = TasksAuthMethodDirectory::class;

    protected const string OAUTH_PROVIDER_DIRECTORY = TasksOAuthProviderDirectory::class;

    protected const ?string BACKUP_CATALOG = BackupCatalog::class;

    protected const ?string LEGAL_CATALOG = TasksLegalCatalog::class;

    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::HILOS_USERS,
        HilosFeature::LOGS,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::CODE_CHANNELS,
        HilosFeature::BACKUP,
    ];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        BackupPage::PAGE => BackupPage::class,
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
        ProfileSecurityPage::PAGE => ProfileSecurityPage::class,
        SecurityOAuthPage::PAGE => SecurityOAuthPage::class,
        SecurityOAuthProviderPage::PAGE => SecurityOAuthProviderPage::class,
        SecuritySignInMethodsPage::PAGE => SecuritySignInMethodsPage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        TasksAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TasksAgent::class,
            AgentRegistryKey::DAEMON => TasksAgentDaemon::class,
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
        BackupAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BackupAgent::class,
            AgentRegistryKey::DAEMON => BackupAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
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
        TasksDbContext::users => [
            SharedOwnersKey::OWNERS => [TasksAgent::class, SessionsLibraryAgent::class, UsersLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-630',
        ],
        HilosDbContext::identities => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, OAuthAgent::class],
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
        TasksTableContext::settings => HilosSettingsTable::class,
        TasksTableContext::hilosUsers => HilosUsersTable::class,
        TasksTableContext::hilosBackups => HilosBackupHistoryTable::class,
        TasksTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
        TasksTableContext::hilosLogKeys => HilosLogKeysTable::class,
        TasksTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        TasksTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        TasksTableContext::hilosSecurityOauthProviders => HilosSecurityOAuthProvidersTable::class,
        TasksTableContext::hilosSecurityOauthProviderFields => HilosSecurityOAuthProviderFieldsTable::class,
        TasksTableContext::hilosSecurityOauthRedirect => HilosSecurityOAuthRedirectTable::class,
        TasksTableContext::hilosSecuritySignInMethods => HilosSecuritySignInMethodsTable::class,
        TasksTableContext::hilosSecurityTwoFactor => HilosSecurityTwoFactorTable::class,
    ];

    public const array BROWSER_TABLES = [
        UserDetailBrowserTable::TABLE => UserDetailBrowserTable::class,
    ];

    public const array PAGE_TABLES = [
        SettingsPage::PAGE => [
            TasksTableContext::settings => [],
        ],
        BackupPage::PAGE => [
            TasksTableContext::hilosBackups => [],
            TasksTableContext::hilosVerifierCircle => [],
        ],
        LogsKeysPage::PAGE => [
            TasksTableContext::hilosLogKeys => [],
        ],
        LogsRotationsPage::PAGE => [
            TasksTableContext::hilosLogRotations => [],
        ],
        LogsWorkersPage::PAGE => [
            TasksTableContext::hilosLogWorkers => [],
        ],
        SecurityOAuthPage::PAGE => [
            TasksTableContext::hilosSecurityOauthRedirect => [],
            TasksTableContext::hilosSecurityOauthProviders => [],
        ],
        SecurityOAuthProviderPage::PAGE => [
            TasksTableContext::hilosSecurityOauthProviders => [],
            TasksTableContext::hilosSecurityOauthProviderFields => [],
        ],
        SecuritySignInMethodsPage::PAGE => [
            TasksTableContext::hilosSecuritySignInMethods => [],
        ],
        SecurityTwoFactorPage::PAGE => [
            TasksTableContext::hilosSecurityTwoFactor => [],
        ],
        UsersPage::PAGE => [
            TasksTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            UserDetailBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    HilosPageRouteParams::HILOS_USER_USER_ID => TasksBrowserRef::HILOS_USER_ID,
                ],
            ],
        ],
    ];

    /**
     * Creates the tasks database context.
     *
     * @return TasksDbContext Tasks database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new TasksDbContext();
    }

    /**
     * Creates the tasks runtime context.
     *
     * @return ?TasksRtContext Tasks runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new TasksRtContext();
    }

    /**
     * Creates the tasks table context.
     *
     * @return ?TasksTableContext Tasks table context
     */
    protected static function createTable(): ?TableContext
    {
        return new TasksTableContext();
    }

    /**
     * Creates the tasks browser-facing context.
     *
     * @return ?TasksBrowserContext Tasks browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new TasksBrowserContext();
    }
}
