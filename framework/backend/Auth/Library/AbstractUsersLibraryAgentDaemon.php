<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * AbstractUsersLibraryAgentDaemon - daemon proxy for the users library (HIL-622).
 *
 * Places {@see AbstractUsersLibraryAgent} as a cluster-leader-pinned monopolistic singleton:
 * monopolistic because minting an account is a claim only one process may hold - two
 * processes racing on the same identifier would each see it free - and leader-pinned because
 * that is what "one process cluster-wide" means today. The split of that flag into a scope
 * and a placement is HIL-667; on a single node the two forms are the same process.
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
