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
     * Subdirectory under the daemon log root rotation renames the live logs into (HIL-870).
     *
     * Rotation always lands here, whatever the archive is: a rename inside the log root is on one
     * device by construction, which is what keeps the moment of rotation instantaneous. The batch
     * is carried on to the archive afterwards, out of that moment.
     *
     * Layout: `{dirname(DAEMON_LOG_FILE)}/{LOG_STAGING_SUBDIR_NAME}/{TIMESTAMP}/`
     */
    public const string LOG_STAGING_SUBDIR_NAME = 'staging';

    /**
     * Prefix of the half-carried copy of a batch inside the archive (HIL-870).
     *
     * The leading dot is deliberate: {@see self::TIMESTAMP_DIR_NAME_PATTERN} does not recognize
     * such a name as a batch, so a batch that has not arrived whole reaches neither the archive
     * index nor the cleanup.
     */
    public const string INCOMING_DIR_PREFIX = '.incoming-';

    /**
     * Format string for a rotation batch directory name (PHP date/createFromFormat).
     *
     * Example folder name: 2026-03-23-19-01-20
     */
    public const string TIMESTAMP_FORMAT = 'Y-m-d-H-i-s';

    /**
     * Regex matching a single rotation batch directory name under the archive subdirectory.
     *
     * Must correspond to {@see self::TIMESTAMP_FORMAT}.
     */
    public const string TIMESTAMP_DIR_NAME_PATTERN = '/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/';
}
