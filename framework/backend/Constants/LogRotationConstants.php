<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * Log rotation and archive directory layout.
 *
 * Used by DockerManager::rotateLogs() and AbstractHilosLogsPage (Hilos logs overview).
 * Keep TIMESTAMP_DIR_NAME_PATTERN in sync with TIMESTAMP_FORMAT.
 */
final class LogRotationConstants
{
    /**
     * Subdirectory under the daemon log root that holds rotated log batches.
     *
     * Layout: `{dirname(DAEMON_LOG_FILE)}/{LOG_ARCHIVE_SUBDIR_NAME}/{TIMESTAMP}/`
     */
    public const string LOG_ARCHIVE_SUBDIR_NAME = 'archive';

    /**
     * Format string for a rotation batch directory name (PHP date/createFromFormat).
     *
     * Example folder name: 2026-03-23-19-01-20
     */
    public const string TIMESTAMP_FORMAT = 'Y-m-d-H-i-s';

    /**
     * Regex matching a single rotation batch directory name under the archive subdirectory.
     *
     * Must correspond to {@see TIMESTAMP_FORMAT}.
     */
    public const string TIMESTAMP_DIR_NAME_PATTERN = '/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/';
}
