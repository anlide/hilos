<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

/**
 * Thrown when a step of the backup create path fails.
 *
 * The create path is all-or-nothing: a failed dump, archive, or atomic publish
 * aborts the whole run and leaves no partial archive behind. The message carries
 * the failing step (which connection, mysqldump stderr, etc.) for the operator log.
 */
final class BackupDumpFailedException extends BackupException
{
}
