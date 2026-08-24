<?php

declare(strict_types=1);

namespace Hilos\Auth\Code;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgentDaemon;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the phone one-time-code agent (HIL-492).
 *
 * A monopolistic worker, the shape {@see AbstractOAuthAgentDaemon} has, and for the
 * same reason: the pool of in-flight code requests is plain memory in one process, so
 * a second instance would hold a second pool and the outcome of a request would depend
 * on which one adopted it. One instance also means one place that speaks to a
 * messenger gateway, which is what keeps a per-request-id billing promise honest.
 *
 * It does NOT pin to the cluster leader. Nothing here is a truth source - the challenge
 * lives in the shared database and the send gate reads it there - so pinning would buy
 * nothing and would route every node's code request through one node's socket. What
 * must be global is the limit, and it is global already.
 */
final class AuthCodeAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_AUTH_CODE;

    /**
     * The op pool is process memory with exactly one owner, so the agent needs its own worker.
     *
     * @return bool True: a monopolistic agent
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
