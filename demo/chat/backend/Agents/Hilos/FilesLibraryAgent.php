<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Files\Library\AbstractFilesLibraryAgent;

/**
 * The chat demo's files library - a name and nothing else (HIL-336).
 *
 * The files registry has no project half: the framework owns the table, the bind frame and the
 * janitor ({@see AbstractFilesLibraryAgent}), and the class exists because every Hilos agent is
 * mounted through a concrete class in the project's registry.
 *
 * Registered under {@see HilosAgentType::HILOS_FILES_LIBRARY} by this demo's own topology, which
 * {@see HilosFeature::FILES} requires of every project declaring it.
 */
final class FilesLibraryAgent extends AbstractFilesLibraryAgent
{
}
