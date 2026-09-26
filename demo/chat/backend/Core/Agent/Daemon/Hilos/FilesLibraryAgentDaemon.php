<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Files\Library\AbstractFilesLibraryAgentDaemon;

/**
 * FilesLibraryAgentDaemon - daemon proxy for the chat files library (HIL-336).
 *
 * Inherits the framework placement whole: a monopolistic singleton, because the files registry
 * has one writer or it has none. Where that process runs is left to the placement policy in this
 * demo's registry entry.
 */
final class FilesLibraryAgentDaemon extends AbstractFilesLibraryAgentDaemon
{
}
