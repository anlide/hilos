<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * AbstractUsersLibraryAgentDaemon - daemon proxy for the users library (HIL-622).
 *
 * Places {@see AbstractUsersLibraryAgent} as a cluster-wide monopolistic singleton:
 * monopolistic because minting an account is a claim only one process may hold - two
 * processes racing on the same identifier would each see it free. Where that one process
 * runs is the placement policy's to say ({@see AgentPlacement::POLICY} in a project's
 * registry entry) and not the leader's, because an entity library is placed rather than
 * pinned - the rule is docs/agents/architecture/entity-libraries.md, "Placement Is Two Axes,
 * Not One Flag". Neither axis is this daemon's to answer: both belong to that entry, where
 * the scope one stays unwritten because CLUSTER is already its default.
 *
 * A project subclasses it alongside a concrete {@see AbstractUsersLibraryAgent} and registers
 * both under {@see HilosAgentType::HILOS_USERS_LIBRARY}.
 */
abstract class AbstractUsersLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USERS_LIBRARY;

    /**
     * The library is the single owner of the user set.
     *
     * @return bool True because minting an account may happen in one process only
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
