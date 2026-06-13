<?php

declare(strict_types=1);

namespace Demo\SimplePoll;

use Demo\SimplePoll\Agents\Hilos\DemoHilosAgent;
use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\SimplePoll\Core\Agent\Daemon\PollAgentDaemon;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Environment\PollEnvCatalog;
use Demo\SimplePoll\Pages\Hilos\AboutPage;
use Demo\SimplePoll\Pages\Hilos\DashboardPage;
use Demo\SimplePoll\Pages\Hilos\LicencePage;
use Demo\SimplePoll\Pages\Hilos\PrivacyPage;
use Demo\SimplePoll\Pages\Hilos\TermsPage;
use Demo\SimplePoll\Pages\MainPage;
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
 * @property-read PollDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 */
final class Hilos extends \Hilos\Hilos
{
    protected const string ENV_CATALOG = PollEnvCatalog::class;

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicencePage::PAGE => LicencePage::class,
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

    /**
     * Creates the simple-poll database context.
     *
     * @return PollDbContext Simple-poll database context
     */
    protected static function createDb(): DbContext
    {
        return new PollDbContext();
    }
}
