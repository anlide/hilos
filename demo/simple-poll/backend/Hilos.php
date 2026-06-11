<?php

declare(strict_types=1);

namespace Demo\SimplePoll;

use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Core\Agent\Daemon\PollAgentDaemon;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Environment\PollEnvCatalog;
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
    ];

    public const array AGENTS = [
        PollAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => PollAgent::class,
            AgentRegistryKey::DAEMON => PollAgentDaemon::class,
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
