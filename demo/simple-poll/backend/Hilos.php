<?php

declare(strict_types=1);

namespace Demo\SimplePoll;

use Demo\SimplePoll\Agents\Hilos\DemoHilosAgent;
use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Browser\PollBrowserContext;
use Demo\SimplePoll\Browser\PollBrowserRef;
use Demo\SimplePoll\Browser\Table\UserDetailBrowserTable;
use Demo\SimplePoll\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\SimplePoll\Core\Agent\Daemon\PollAgentDaemon;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Database\Settings\PollSettingsCatalog;
use Demo\SimplePoll\Environment\PollEnvCatalog;
use Demo\SimplePoll\Pages\Hilos\AboutPage;
use Demo\SimplePoll\Pages\Hilos\DashboardPage;
use Demo\SimplePoll\Pages\Hilos\LicensePage;
use Demo\SimplePoll\Pages\Hilos\PrivacyPage;
use Demo\SimplePoll\Pages\Hilos\SettingsPage;
use Demo\SimplePoll\Pages\Hilos\TermsPage;
use Demo\SimplePoll\Pages\Hilos\NotificationsPage;
use Demo\SimplePoll\Pages\Hilos\Users\UserPage;
use Demo\SimplePoll\Pages\Hilos\Users\UsersPage;
use Demo\SimplePoll\Pages\MainPage;
use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Demo\SimplePoll\Tables\HilosUser\HilosUsersTable;
use Demo\SimplePoll\Tables\PollTableContext;
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
 * @property-read PollDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read PollRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read PollTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read PollBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = PollEnvCatalog::class;

    protected const string SETTINGS_CATALOG = PollSettingsCatalog::class;

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
        PollAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => PollAgent::class,
            AgentRegistryKey::DAEMON => PollAgentDaemon::class,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
        ],
    ];

    public const array TABLES = [
        PollTableContext::settings => HilosSettingsTable::class,
        PollTableContext::hilosUsers => HilosUsersTable::class,
    ];

    public const array BROWSER_TABLES = [
        UserDetailBrowserTable::TABLE => UserDetailBrowserTable::class,
    ];

    public const array PAGE_TABLES = [
        SettingsPage::PAGE => [
            PollTableContext::settings => [],
        ],
        UsersPage::PAGE => [
            PollTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            UserDetailBrowserTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    HilosPageRouteParams::HILOS_USER_USER_ID => PollBrowserRef::HILOS_USER_ID,
                ],
            ],
        ],
    ];

    /**
     * Creates the simple-poll database context.
     *
     * @return PollDbContext Simple-poll database context
     */
    protected static function createDb(): DbContext
    {
        return new PollDbContext();
    }

    /**
     * Creates the simple-poll runtime context.
     *
     * @return ?PollRtContext Simple-poll runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new PollRtContext();
    }

    /**
     * Creates the simple-poll table context.
     *
     * @return ?PollTableContext Simple-poll table context
     */
    protected static function createTable(): ?TableContext
    {
        return new PollTableContext();
    }

    /**
     * Creates the simple-poll browser-facing context.
     *
     * @return ?PollBrowserContext Simple-poll browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new PollBrowserContext();
    }
}
