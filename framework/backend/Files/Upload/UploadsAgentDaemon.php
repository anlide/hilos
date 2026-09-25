<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the uploads agent (HIL-135).
 *
 * A project registers it with {@see UploadsAgent} under {@see HilosAgentType::HILOS_UPLOADS},
 * placed by the policy like the libraries; there is nothing to subclass, since a project's only
 * say in uploads is its targets.
 */
final class UploadsAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_UPLOADS;

    /**
     * The agent is the single owner of the uploads, and writing their chunks to disk does not
     * share a process with other agents.
     *
     * @return bool True: a monopolistic agent
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
