<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

/**
 * Thrown when a step of the backup restore path fails or refuses to run.
 *
 * Raised both by the preflight refusals (missing/ambiguous archive, failed digest
 * check, unavailable anonymizer) and by the destructive steps themselves (extract,
 * per-connection import). The message names the failing step and connection for the
 * operator. A failure past the first import leaves the database partially restored;
 * surfacing and reconciling that state is HIL-436.
 */
class RestoreFailedException extends BackupException
{
}
