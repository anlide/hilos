<?php

declare(strict_types=1);

namespace Demo\Tasks;

use Demo\Tasks\Agents\Hilos\DemoHilosAgent;
use Demo\Tasks\Agents\TasksAgent;
use Demo\Tasks\Browser\Table\UserDetailBrowserTable;
use Demo\Tasks\Browser\TasksBrowserContext;
use Demo\Tasks\Browser\TasksBrowserRef;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\TasksAgentDaemon;
use Demo\Tasks\Database\Settings\TasksSettingsCatalog;
use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Environment\TasksEnvCatalog;
use Demo\Tasks\Pages\Hilos\AboutPage;
use Demo\Tasks\Pages\Hilos\DashboardPage;
use Demo\Tasks\Pages\Hilos\LicensePage;
use Demo\Tasks\Pages\Hilos\PrivacyPage;
use Demo\Tasks\Pages\Hilos\SettingsPage;
use Demo\Tasks\Pages\Hilos\TermsPage;
use Demo\Tasks\Pages\Hilos\NotificationsPage;
use Demo\Tasks\Pages\Hilos\Users\UserPage;
use Demo\Tasks\Pages\Hilos\Users\UsersPage;
use Demo\Tasks\Pages\MainPage;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
use Demo\Tasks\Tables\HilosUser\HilosUsersTable;
use Demo\Tasks\Tables\TasksTableContext;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos as HilosFacade;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Hilos - Main app facade for data access.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]
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

    protected const array FEATURES = [
        HilosFeature::SETTINGS,
        HilosFeature::HILOS_USERS,
        HilosFeature::NOTIFICATIONS,
    ];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
        NotificationsPage::PAGE => NotificationsPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicensePage::PAGE => LicensePage::class,
    ];

    public const array AGENTS = [
        TasksAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TasksAgent::class,
            AgentRegistryKey::DAEMON => TasksAgentDaemon::class,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
        ],
    ];

    public const array TABLES = [
        TasksTableContext::settings => HilosSettingsTable::class,
        TasksTableContext::hilosUsers => HilosUsersTable::class,
    ];

    public const array BROWSER_TABLES = [
        UserDetailBrowserTable::TABLE => UserDetailBrowserTable::class,
    ];

    public const array PAGE_TABLES = [
        SettingsPage::PAGE => [
            TasksTableContext::settings => [],
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
    protected static function createDb(): DbContext
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
