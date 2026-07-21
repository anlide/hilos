<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\Agent;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * AbstractOAuthAgentDaemon - daemon proxy for the OAuth login async agent (HIL-281).
 *
 * Places {@see AbstractOAuthAgent} as a cluster-leader-pinned monopolistic singleton
 * (the default {@see requiresClusterLeadership()} keeps it leader-only, and monopolistic
 * makes it the single owner of the in-flight-login pool cluster-wide). The agent itself
 * pipelines, so one instance serves a login burst concurrently; flip this to per-worker
 * (both flags false) only if login volume ever outgrows a single pipelining singleton.
 *
 * A project subclasses it alongside a concrete {@see AbstractOAuthAgent} and registers
 * both under {@see HilosAgentType::HILOS_OAUTH}.
 */
abstract class AbstractOAuthAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_OAUTH;

    /**
     * The OAuth agent is the single owner of the in-flight-login pool.
     *
     * @return bool True because the OAuth agent is monopolistic
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
