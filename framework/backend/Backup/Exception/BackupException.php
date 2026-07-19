<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

use Hilos\HilosException;

/**
 * Base exception for the backup subsystem.
 *
 * Callers of the create path document a single `@throws BackupException` for the
 * whole family; concrete children name the specific failure.
 */
class BackupException extends HilosException
{
}
