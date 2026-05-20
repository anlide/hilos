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
use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\Table\AttachmentDraftsBrowserTable;
use Demo\Chat\Browser\Table\BotDetailBrowserTable;
use Demo\Chat\Browser\Table\GuardianAgentStatusDetailBrowserTable;
use Demo\Chat\Browser\Table\GuardianAgentStatusesBrowserTable;
use Demo\Chat\Browser\Table\MainBotsBrowserTable;
use Demo\Chat\Browser\Table\MainEventsBrowserTable;
use Demo\Chat\Browser\Table\MainUsersBrowserTable;
use Demo\Chat\Browser\Table\SelfConnectionBrowserTable;
use Demo\Chat\Browser\Table\UserDetailBrowserTable;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Settings\ChatSettingsAccessor;
use Demo\Chat\Environment\ChatEnvAccessor;
use Demo\Chat\Fs\ChatFsContext;
use Demo\Chat\Groups\SessionGroup;
use Demo\Chat\Pages\AdminBotsPage;
use Demo\Chat\Pages\AdminModeratorPage;
use Demo\Chat\Pages\AdminPage;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
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
use Demo\Chat\Pages\Hilos\Roles\RolesPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Chat\Pages\Hilos\Security\SecurityPage;
use Demo\Chat\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Demo\Chat\Pages\Hilos\Sil\SilDashboardPage;
use Demo\Chat\Pages\Hilos\Sil\SilRequestsPage;
use Demo\Chat\Pages\Hilos\Sil\SilUserHistoryPage;
use Demo\Chat\Pages\Hilos\Users\UserPage as HilosUserPage;
use Demo\Chat\Pages\Hilos\Users\UsersPage as HilosUsersPage;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Pages\ModeratorPage;
use Demo\Chat\Pages\ProfilePage;
use Demo\Chat\Pages\UserPage as ChatUserPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\AdminUser\AdminUsersTable;
use Demo\Chat\Tables\Bot\BotsTable;
use Demo\Chat\Tables\ChatTableContext;
use Demo\Chat\Tables\HilosUser\HilosUsersTable;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Demo\Chat\Tables\Settings\SettingsTable;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Runtime\View\Context\RtContext;

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
 * @property-read ChatEnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read ChatSettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read ChatRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read ChatTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read ChatBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 * @property-read ChatFsContext $fs Filesystem context (narrows parent's FsContext for IDE)
 */
final class Hilos extends \Hilos\Hilos
{
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
    ];

    public const array GROUPS = [
        SessionGroup::GROUP => SessionGroup::class,
    ];

    public const array AGENTS = [
        ChatAgent::AGENT_TYPE => ChatAgent::class,
        LibraryAgent::AGENT_TYPE => LibraryAgent::class,
        ChatContextAnalyzerAgent::AGENT_TYPE => ChatContextAnalyzerAgent::class,
        BotAgent::AGENT_TYPE => BotAgent::class,
        ModeratorAgent::AGENT_TYPE => ModeratorAgent::class,
        DemoHilosAgent::AGENT_TYPE => DemoHilosAgent::class,
        DemoHilosGuardianAgent::AGENT_TYPE => DemoHilosGuardianAgent::class,
        DemoHilosAnalyticsAgent::AGENT_TYPE => DemoHilosAnalyticsAgent::class,
        DemoHilosLogsAgent::AGENT_TYPE => DemoHilosLogsAgent::class,
    ];

    public const array TABLES = [
        ChatTableContext::adminUsers => AdminUsersTable::class,
        ChatTableContext::hilosUsers => HilosUsersTable::class,
        ChatTableContext::bots => BotsTable::class,
        ChatTableContext::moderatorPromptPieces => ModeratorPromptPiecesTable::class,
        ChatTableContext::settings => SettingsTable::class,
    ];

    public const array BROWSER_TABLES = [
        MainEventsBrowserTable::TABLE => MainEventsBrowserTable::class,
        MainUsersBrowserTable::TABLE => MainUsersBrowserTable::class,
        MainBotsBrowserTable::TABLE => MainBotsBrowserTable::class,
        SelfConnectionBrowserTable::TABLE => SelfConnectionBrowserTable::class,
        AttachmentDraftsBrowserTable::TABLE => AttachmentDraftsBrowserTable::class,
        BotDetailBrowserTable::TABLE => BotDetailBrowserTable::class,
        UserDetailBrowserTable::TABLE => UserDetailBrowserTable::class,
        GuardianAgentStatusesBrowserTable::TABLE => GuardianAgentStatusesBrowserTable::class,
        GuardianAgentStatusDetailBrowserTable::TABLE => GuardianAgentStatusDetailBrowserTable::class,
    ];

    public const array PAGE_TABLES = [
        MainPage::PAGE => [
            MainEventsBrowserTable::TABLE => [],
            MainUsersBrowserTable::TABLE => [],
            MainBotsBrowserTable::TABLE => [],
            SelfConnectionBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
            AttachmentDraftsBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ProfilePage::PAGE => [
            SelfConnectionBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ChatBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ChatUserPage::PAGE => [
            UserDetailBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    HilosPageRouteParams::HILOS_USER_USER_ID => ChatBrowserRef::USER_ID,
                ],
            ],
        ],
        BotPage::PAGE => [
            BotDetailBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    BotPageSubscribeParams::BOT_ID => ChatBrowserRef::BOT_ID,
                ],
            ],
        ],
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
     * Creates the project environment accessor with the chat env catalog.
     *
     * @return EnvAccessor Environment accessor
     */
    protected static function createEnv(): EnvAccessor
    {
        return new ChatEnvAccessor();
    }

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
     * Creates the project settings accessor with the chat settings catalog.
     *
     * @return SettingsAccessor Settings accessor
     */
    protected static function createSetting(): SettingsAccessor
    {
        return new ChatSettingsAccessor();
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
