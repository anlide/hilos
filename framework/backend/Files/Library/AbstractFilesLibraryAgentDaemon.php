<?php

declare(strict_types=1);

namespace Hilos\Files\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * AbstractFilesLibraryAgentDaemon - daemon proxy for the files library (HIL-336).
 *
 * Places {@see AbstractFilesLibraryAgent} as a monopolistic singleton: the files registry has one
 * writer or it has none. Where that process runs is the placement policy's to say
 * ({@see AgentPlacement::POLICY} in a project's registry entry), as for the other entity
 * libraries.
 *
 * A project subclasses it alongside a concrete {@see AbstractFilesLibraryAgent} and registers
 * both under {@see HilosAgentType::HILOS_FILES_LIBRARY}.
 */
abstract class AbstractFilesLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_FILES_LIBRARY;

    /**
     * The library is the single owner of the files registry.
     *
     * @return bool True because the registry may be written in one process only
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
