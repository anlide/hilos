<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliManager;

/**
 * Marker for a CLI command the bootstrap must not open a database connection for: the
 * command either connects on its own (db:test:reset drops and recreates the very database
 * a bootstrap connect would have opened) or needs no database at all.
 *
 * Implementing it is the whole declaration; there is no method to write. The bootstrap
 * asks {@see CliManager::requiresDatabase()} rather than matching the command name against
 * a list it keeps, so a project command declares the same contract the same way, without
 * the entrypoint knowing its name.
 */
interface DatabaseFreeCommand
{
}
