<?php

declare(strict_types=1);

namespace Demo\SimpleTodo;

use Demo\SimpleTodo\Agents\Hilos\DemoHilosAgent;
use Demo\SimpleTodo\Agents\TodoAgent;
use Demo\SimpleTodo\Browser\TodoBrowserContext;
use Demo\SimpleTodo\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\SimpleTodo\Core\Agent\Daemon\TodoAgentDaemon;
use Demo\SimpleTodo\Database\Settings\TodoSettingsCatalog;
use Demo\SimpleTodo\Database\TodoDbContext;
use Demo\SimpleTodo\Environment\TodoEnvCatalog;
use Demo\SimpleTodo\Pages\Hilos\AboutPage;
use Demo\SimpleTodo\Pages\Hilos\DashboardPage;
use Demo\SimpleTodo\Pages\Hilos\LicensePage;
use Demo\SimpleTodo\Pages\Hilos\PrivacyPage;
use Demo\SimpleTodo\Pages\Hilos\SettingsPage;
use Demo\SimpleTodo\Pages\Hilos\TermsPage;
use Demo\SimpleTodo\Pages\MainPage;
use Demo\SimpleTodo\Tables\TodoTableContext;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Hilos - Main app facade for data access.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]
 * - Hilos::$db->settings
 * - Hilos::$setting->catalog()
 * - Hilos::$table->settings
 *
 * @property-read TodoDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read TodoTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read TodoBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends \Hilos\Hilos
{
    protected const string ENV_CATALOG = TodoEnvCatalog::class;

    protected const string SETTINGS_CATALOG = TodoSettingsCatalog::class;

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicensePage::PAGE => LicensePage::class,
    ];

    public const array AGENTS = [
        TodoAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TodoAgent::class,
            AgentRegistryKey::DAEMON => TodoAgentDaemon::class,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
        ],
    ];

    public const array TABLES = [
        TodoTableContext::settings => HilosSettingsTable::class,
    ];

    public const array PAGE_TABLES = [
        SettingsPage::PAGE => [
            TodoTableContext::settings => [],
        ],
    ];

    /**
     * Creates the simple-todo database context.
     *
     * @return TodoDbContext Simple-todo database context
     */
    protected static function createDb(): DbContext
    {
        return new TodoDbContext();
    }

    /**
     * Creates the simple-todo table context.
     *
     * @return ?TodoTableContext Simple-todo table context
     */
    protected static function createTable(): ?TableContext
    {
        return new TodoTableContext();
    }

    /**
     * Creates the simple-todo browser-facing context.
     *
     * @return ?TodoBrowserContext Simple-todo browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new TodoBrowserContext();
    }
}
