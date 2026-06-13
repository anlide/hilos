<?php

declare(strict_types=1);

namespace Demo\SimpleTodo;

use Demo\SimpleTodo\Agents\Hilos\DemoHilosAgent;
use Demo\SimpleTodo\Agents\TodoAgent;
use Demo\SimpleTodo\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\SimpleTodo\Core\Agent\Daemon\TodoAgentDaemon;
use Demo\SimpleTodo\Database\TodoDbContext;
use Demo\SimpleTodo\Environment\TodoEnvCatalog;
use Demo\SimpleTodo\Pages\Hilos\AboutPage;
use Demo\SimpleTodo\Pages\Hilos\DashboardPage;
use Demo\SimpleTodo\Pages\Hilos\LicencePage;
use Demo\SimpleTodo\Pages\Hilos\PrivacyPage;
use Demo\SimpleTodo\Pages\Hilos\TermsPage;
use Demo\SimpleTodo\Pages\MainPage;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;

/**
 * Hilos - Main app facade for data access.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]
 * - Hilos::$db->settings
 *
 * @property-read TodoDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 */
final class Hilos extends \Hilos\Hilos
{
    protected const string ENV_CATALOG = TodoEnvCatalog::class;

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicencePage::PAGE => LicencePage::class,
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

    /**
     * Creates the simple-todo database context.
     *
     * @return TodoDbContext Simple-todo database context
     */
    protected static function createDb(): DbContext
    {
        return new TodoDbContext();
    }
}
