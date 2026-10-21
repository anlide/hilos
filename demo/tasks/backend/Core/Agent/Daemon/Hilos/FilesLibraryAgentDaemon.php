<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Agent\Daemon\Hilos;

use Hilos\Files\Library\AbstractFilesLibraryAgentDaemon;

/** Daemon proxy for the tasks files library, inheriting its monopolistic worker. */
final class FilesLibraryAgentDaemon extends AbstractFilesLibraryAgentDaemon
{
}
