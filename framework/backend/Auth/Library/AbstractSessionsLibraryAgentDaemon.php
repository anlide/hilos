<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * AbstractSessionsLibraryAgentDaemon - daemon proxy for the sessions library (HIL-710).
 *
 * Places {@see AbstractSessionsLibraryAgent} as a monopolistic singleton, which is the
 * whole point of the move: a session is resolved, rotated and bound in one process or it is
 * raced, and two processes accepting handshakes for the same cookie would each believe they
 * had created it. Where that process runs is the placement policy's to say
 * ({@see AgentPlacement::POLICY} in a project's registry entry), because sessions are the
 * hot half of the sign-in surface - every handshake touches them - and pinning them to the
 * leader beside the cold users library would put both on one node for no reason.
 *
 * A project subclasses it alongside a concrete {@see AbstractSessionsLibraryAgent} and
 * registers both under {@see HilosAgentType::HILOS_SESSIONS_LIBRARY}.
 */
abstract class AbstractSessionsLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SESSIONS_LIBRARY;

    /**
     * The library is the single owner of the session set.
     *
     * @return bool True because resolving and rotating a session may happen in one process only
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
