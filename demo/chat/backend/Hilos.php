<?php

declare(strict_types=1);

namespace Demo\Chat;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\ChatContextAnalyzerAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Chat\Agents\Hilos\DemoHilosGuardianAgent;
use Demo\Chat\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Agents\OAuthAgent;
use Demo\Chat\Core\Agent\Daemon\BotAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ChatAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ChatContextAnalyzerAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosGuardianAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\LibraryAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\ModeratorAgentDaemon;
use Demo\Chat\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\Chat\Backup\BackupCatalog;
use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\Data\BotStatusBrowserData;
use Demo\Chat\Browser\Data\SelfConnectionBrowserData;
use Demo\Chat\Browser\Data\UserPresenceBrowserData;
use Demo\Chat\Browser\List\AttachmentDraftsBrowserList;
use Demo\Chat\Browser\List\MainBotsBrowserList;
use Demo\Chat\Browser\List\MainEventsBrowserList;
use Demo\Chat\Browser\List\MainUsersBrowserList;
use Demo\Chat\Browser\List\ProfileIdentitiesBrowserList;
use Demo\Chat\Browser\Table\GuardianAgentStatusDetailBrowserTable;
use Demo\Chat\Browser\Table\GuardianAgentStatusesBrowserTable;
use Demo\Chat\Browser\Table\UserDetailBrowserTable;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Environment\ChatEnvCatalog;
use Demo\Chat\Environment\ChatLlmProfileCatalog;
use Demo\Chat\Environment\ChatLlmProfileOverrideSource;
use Demo\Chat\Fs\ChatFsContext;
use Demo\Chat\Groups\SessionGroup;
use Demo\Chat\Notification\ChatDeliveryChannelRegistry;
use Demo\Chat\Pages\AdminBotsPage;
use Demo\Chat\Pages\AdminModeratorPage;
use Demo\Chat\Pages\AdminPage;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Pages\Hilos\AboutPage;
use Demo\Chat\Pages\Hilos\AnalyticsPage;
use Demo\Chat\Pages\Hilos\Backup\BackupPage;
use Demo\Chat\Pages\Hilos\Billing\BillingPage;
use Demo\Chat\Pages\Hilos\Billing\BillingPaymentsPage;
use Demo\Chat\Pages\Hilos\Billing\BillingProviderPage;
use Demo\Chat\Pages\Hilos\Billing\BillingRefundsPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\Chat\Pages\Hilos\DashboardPage;
use Demo\Chat\Pages\Hilos\Guardian\GuardianAgentPage;
use Demo\Chat\Pages\Hilos\GuardianPage;
use Demo\Chat\Pages\Hilos\I18n\Details\ActionDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\GroupDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\UiPageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\ActionsListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\EmailsListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\EntitiesListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\GroupsListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\UiPagesListPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateActionErrorPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateEmailPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateEntityPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateGroupItemPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateGroupPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateUiPageItemPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateUiPagePage;
use Demo\Chat\Pages\Hilos\I18nPage;
use Demo\Chat\Pages\Hilos\LicensePage;
use Demo\Chat\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Chat\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Chat\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\Chat\Pages\Hilos\Logs\LogsViewPage;
use Demo\Chat\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsDashboardPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpLogsPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpLogsViewPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpPage;
use Demo\Chat\Pages\Hilos\Operations\OperationsPage;
use Demo\Chat\Pages\Hilos\NotificationsPage;
use Demo\Chat\Pages\Hilos\PrivacyPage;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Pages\Hilos\Roles\RolesPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Chat\Pages\Hilos\Security\SecurityPage;
use Demo\Chat\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Demo\Chat\Pages\Hilos\Sil\SilDashboardPage;
use Demo\Chat\Pages\Hilos\Sil\SilRequestsPage;
use Demo\Chat\Pages\Hilos\Sil\SilUserHistoryPage;
use Demo\Chat\Pages\Hilos\TermsPage;
use Demo\Chat\Pages\Hilos\Users\UserPage as HilosUserPage;
use Demo\Chat\Pages\Hilos\Users\UsersPage as HilosUsersPage;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Pages\ModeratorPage;
use Demo\Chat\Pages\UserPage as ChatUserPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\AdminUser\AdminUsersTable;
use Demo\Chat\Tables\Bot\BotsTable;
use Demo\Chat\Tables\ChatTableContext;
use Demo\Chat\Tables\HilosUser\HilosUsersTable;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Hilos - Main app facade for data access.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]
 * - Hilos::$db->users
 * - Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL]->string()
 * - Hilos::$rt->connections
 * - Hilos::$rt->userStates
 * - Hilos::$table->users
 * - Hilos::$browser
 * - Hilos::$fs->quarantine, Hilos::$fs->published, Hilos::$fs->tmp
 *
 * @property-read ChatDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read ChatRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read ChatTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read ChatBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 * @property-read ChatFsContext $fs Filesystem context (narrows parent's FsContext for IDE)
 */
final class Hilos extends \Hilos\Hilos
{
    protected const string ENV_CATALOG = ChatEnvCatalog::class;

    protected const string SETTINGS_CATALOG = SettingsCatalog::class;

    protected const string LLM_PROFILE_CATALOG = ChatLlmProfileCatalog::class;

    protected const ?string LLM_PROFILE_OVERRIDE = ChatLlmProfileOverrideSource::class;

    protected const ?string BACKUP_CATALOG = BackupCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = ChatDeliveryChannelRegistry::class;

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        ProfilePage::PAGE => ProfilePage::class,
        ChatUserPage::PAGE => ChatUserPage::class,
        BotPage::PAGE => BotPage::class,
        ModeratorPage::PAGE => ModeratorPage::class,
        AdminPage::PAGE => AdminPage::class,
        AdminUsersPage::PAGE => AdminUsersPage::class,
        AdminModeratorPage::PAGE => AdminModeratorPage::class,
        AdminBotsPage::PAGE => AdminBotsPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        I18nPage::PAGE => I18nPage::class,
        LanguagesListPage::PAGE => LanguagesListPage::class,
        CountriesListPage::PAGE => CountriesListPage::class,
        EntitiesListPage::PAGE => EntitiesListPage::class,
        UiPagesListPage::PAGE => UiPagesListPage::class,
        GroupsListPage::PAGE => GroupsListPage::class,
        ActionsListPage::PAGE => ActionsListPage::class,
        EmailsListPage::PAGE => EmailsListPage::class,
        LanguageDetailPage::PAGE => LanguageDetailPage::class,
        CountryDetailPage::PAGE => CountryDetailPage::class,
        UiPageDetailPage::PAGE => UiPageDetailPage::class,
        GroupDetailPage::PAGE => GroupDetailPage::class,
        ActionDetailPage::PAGE => ActionDetailPage::class,
        TranslateEntityPage::PAGE => TranslateEntityPage::class,
        TranslateUiPagePage::PAGE => TranslateUiPagePage::class,
        TranslateUiPageItemPage::PAGE => TranslateUiPageItemPage::class,
        TranslateGroupPage::PAGE => TranslateGroupPage::class,
        TranslateGroupItemPage::PAGE => TranslateGroupItemPage::class,
        TranslateActionErrorPage::PAGE => TranslateActionErrorPage::class,
        TranslateEmailPage::PAGE => TranslateEmailPage::class,
        GuardianPage::PAGE => GuardianPage::class,
        GuardianAgentPage::PAGE => GuardianAgentPage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        BackupPage::PAGE => BackupPage::class,
        DaemonPage::PAGE => DaemonPage::class,
        DaemonWorkersPage::PAGE => DaemonWorkersPage::class,
        DaemonAgentsPage::PAGE => DaemonAgentsPage::class,
        DaemonCronPage::PAGE => DaemonCronPage::class,
        DaemonWebsocketsPage::PAGE => DaemonWebsocketsPage::class,
        DaemonHttpServerPage::PAGE => DaemonHttpServerPage::class,
        LogsOverviewPage::PAGE => LogsOverviewPage::class,
        LogsKeysPage::PAGE => LogsKeysPage::class,
        LogsWorkersPage::PAGE => LogsWorkersPage::class,
        LogsRotationsPage::PAGE => LogsRotationsPage::class,
        LogsViewPage::PAGE => LogsViewPage::class,
        OperationsPage::PAGE => OperationsPage::class,
        HilosUsersPage::PAGE => HilosUsersPage::class,
        HilosUserPage::PAGE => HilosUserPage::class,
        NotificationsPage::PAGE => NotificationsPage::class,
        RolesPage::PAGE => RolesPage::class,
        McpSkillsDashboardPage::PAGE => McpSkillsDashboardPage::class,
        McpSkillsMcpPage::PAGE => McpSkillsMcpPage::class,
        McpSkillsMcpLogsPage::PAGE => McpSkillsMcpLogsPage::class,
        McpSkillsMcpLogsViewPage::PAGE => McpSkillsMcpLogsViewPage::class,
        SilDashboardPage::PAGE => SilDashboardPage::class,
        SilRequestsPage::PAGE => SilRequestsPage::class,
        SilUserHistoryPage::PAGE => SilUserHistoryPage::class,
        CommunicationsPage::PAGE => CommunicationsPage::class,
        CommunicationsChannelPage::PAGE => CommunicationsChannelPage::class,
        CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
        SecurityPage::PAGE => SecurityPage::class,
        SecurityTwoFactorPage::PAGE => SecurityTwoFactorPage::class,
        SecurityOAuthPage::PAGE => SecurityOAuthPage::class,
        SecurityOAuthProviderPage::PAGE => SecurityOAuthProviderPage::class,
        BillingPage::PAGE => BillingPage::class,
        BillingProviderPage::PAGE => BillingProviderPage::class,
        BillingPaymentsPage::PAGE => BillingPaymentsPage::class,
        BillingRefundsPage::PAGE => BillingRefundsPage::class,
        ChangeLogDashboardPage::PAGE => ChangeLogDashboardPage::class,
        ChangeLogTablesPage::PAGE => ChangeLogTablesPage::class,
        ChangeLogTablePage::PAGE => ChangeLogTablePage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicensePage::PAGE => LicensePage::class,
    ];

    public const array GROUPS = [
        SessionGroup::GROUP => SessionGroup::class,
    ];

    public const array AGENTS = [
        ChatAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ChatAgent::class,
            AgentRegistryKey::DAEMON => ChatAgentDaemon::class,
        ],
        LibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LibraryAgent::class,
            AgentRegistryKey::DAEMON => LibraryAgentDaemon::class,
        ],
        ChatContextAnalyzerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ChatContextAnalyzerAgent::class,
            AgentRegistryKey::DAEMON => ChatContextAnalyzerAgentDaemon::class,
        ],
        BotAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BotAgent::class,
            AgentRegistryKey::DAEMON => BotAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
        ],
        ModeratorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ModeratorAgent::class,
            AgentRegistryKey::DAEMON => ModeratorAgentDaemon::class,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
        ],
        DemoHilosGuardianAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosGuardianAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosGuardianAgentDaemon::class,
        ],
        DemoHilosAnalyticsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAnalyticsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAnalyticsAgentDaemon::class,
        ],
        DemoHilosLogsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosLogsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosLogsAgentDaemon::class,
        ],
        BackupAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BackupAgent::class,
            AgentRegistryKey::DAEMON => BackupAgentDaemon::class,
        ],
        OAuthAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => OAuthAgent::class,
            AgentRegistryKey::DAEMON => OAuthAgentDaemon::class,
        ],
        MailDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => MailDeliveryChannelAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];

    public const array TABLES = [
        ChatTableContext::adminUsers => AdminUsersTable::class,
        ChatTableContext::hilosUsers => HilosUsersTable::class,
        ChatTableContext::bots => BotsTable::class,
        ChatTableContext::moderatorPromptPieces => ModeratorPromptPiecesTable::class,
        ChatTableContext::settings => HilosSettingsTable::class,
        ChatTableContext::hilosBackups => HilosBackupHistoryTable::class,
        ChatTableContext::hilosCommunicationsChannels => HilosCommunicationsChannelsTable::class,
        ChatTableContext::hilosCommunicationsChannelFields => HilosCommunicationsChannelFieldsTable::class,
        ChatTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
    ];

    public const array BROWSER_LISTS = [
        MainEventsBrowserList::LIST => MainEventsBrowserList::class,
        MainUsersBrowserList::LIST => MainUsersBrowserList::class,
        MainBotsBrowserList::LIST => MainBotsBrowserList::class,
        AttachmentDraftsBrowserList::LIST => AttachmentDraftsBrowserList::class,
        ProfileIdentitiesBrowserList::LIST => ProfileIdentitiesBrowserList::class,
    ];

    public const array BROWSER_DATA = [
        SelfConnectionBrowserData::DATA => SelfConnectionBrowserData::class,
        BotStatusBrowserData::DATA => BotStatusBrowserData::class,
        UserPresenceBrowserData::DATA => UserPresenceBrowserData::class,
    ];

    public const array BROWSER_TABLES = [
        UserDetailBrowserTable::TABLE => UserDetailBrowserTable::class,
        GuardianAgentStatusesBrowserTable::TABLE => GuardianAgentStatusesBrowserTable::class,
        GuardianAgentStatusDetailBrowserTable::TABLE => GuardianAgentStatusDetailBrowserTable::class,
    ];

    public const array PAGE_LISTS = [
        MainPage::PAGE => [
            MainEventsBrowserList::LIST => [],
            MainUsersBrowserList::LIST => [],
            MainBotsBrowserList::LIST => [],
            AttachmentDraftsBrowserList::LIST => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ProfilePage::PAGE => [
            ProfileIdentitiesBrowserList::LIST => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
    ];

    public const array PAGE_DATA = [
        MainPage::PAGE => [
            SelfConnectionBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ProfilePage::PAGE => [
            SelfConnectionBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ChatUserPage::PAGE => [
            UserPresenceBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    UserPageSubscribeParams::USER_ID => ChatBrowserRef::USER_ID,
                ],
            ],
        ],
        BotPage::PAGE => [
            BotStatusBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    BotPageSubscribeParams::BOT_ID => ChatBrowserRef::BOT_ID,
                ],
            ],
        ],
    ];

    public const array PAGE_TABLES = [
        AdminUsersPage::PAGE => [
            ChatTableContext::adminUsers => [],
        ],
        AdminModeratorPage::PAGE => [
            ChatTableContext::moderatorPromptPieces => [],
        ],
        AdminBotsPage::PAGE => [
            ChatTableContext::bots => [],
        ],
        SettingsPage::PAGE => [
            ChatTableContext::settings => [],
        ],
        BackupPage::PAGE => [
            ChatTableContext::hilosBackups => [],
        ],
        CommunicationsPage::PAGE => [
            ChatTableContext::hilosCommunicationsChannels => [],
        ],
        CommunicationsChannelPage::PAGE => [
            ChatTableContext::hilosCommunicationsChannelFields => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            ChatTableContext::hilosNotificationDeliveries => [],
        ],
        GuardianPage::PAGE => [
            GuardianAgentStatusesBrowserTable::TABLE => [],
        ],
        GuardianAgentPage::PAGE => [
            GuardianAgentStatusDetailBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID => ChatBrowserRef::HILOS_GUARDIAN_AGENT_ID,
                ],
            ],
        ],
        HilosUsersPage::PAGE => [
            ChatTableContext::hilosUsers => [],
        ],
        HilosUserPage::PAGE => [
            UserDetailBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    HilosPageRouteParams::HILOS_USER_USER_ID => ChatBrowserRef::HILOS_USER_ID,
                ],
            ],
        ],
    ];

    /**
     * Creates the chat database context.
     *
     * @return ChatDbContext Chat database context
     */
    protected static function createDb(): DbContext
    {
        return new ChatDbContext();
    }

    /**
     * Creates the chat runtime context.
     *
     * @return ?ChatRtContext Chat runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new ChatRtContext();
    }

    /**
     * Creates the chat table context.
     *
     * @return ?ChatTableContext Chat table context
     */
    protected static function createTable(): ?TableContext
    {
        return new ChatTableContext();
    }

    /**
     * Creates the chat browser-facing context.
     *
     * @return ?ChatBrowserContext Chat browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new ChatBrowserContext();
    }

    /**
     * Creates the chat filesystem context.
     *
     * @return ?ChatFsContext Chat filesystem context
     */
    protected static function createFs(): ?FsContext
    {
        return new ChatFsContext();
    }

}
