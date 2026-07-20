<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

/**
 * Thrown when a project's backup schedule entry is malformed.
 *
 * A schedule entry must carry a non-empty name and cron expression, a known scope, and a
 * known mechanism, and names must be unique across the schedule. Any violation is a project
 * configuration error surfaced at load time.
 */
final class BackupScheduleException extends BackupException
{
}
