<?php

declare(strict_types=1);

namespace Hilos\AdminViewMode;

use Hilos\Constants\LogStreamConstants;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
use Hilos\Log\LogRootOwnerMarker;
use JsonException;

/**
 * The half of the admin view mode latch that lives in the node's log directory (HIL-1249).
 *
 * A production node that started with the mode off leaves this file beside its log files, and
 * from then on the mode stays closed there. The other half is a row in the database
 * ({@see AdminViewModeLatchTable}): a restore from an old archive can take the row, a sweep of the
 * log directory or a move to another machine can take the file, and it takes both to open the
 * mode again. Whichever half survives, the next start writes the other one back.
 *
 * The file decides by being there. What it carries - the environment, the node and when the mode
 * was closed - is for the person who finds it, so a file that cannot be read or understood still
 * closes the mode: opening it on the strength of a parse failure would undo the latch.
 *
 * The write is the idiom of {@see LogRootOwnerMarker}: a temp file beside it, then a rename, so a
 * read in progress never sees half a file. The basename does not end in `.log`: the log walk and
 * the rotation glob {@see LogStreamConstants::LIVE_STREAM_GLOB}, so neither counts it or moves it.
 *
 * Static because the file has no state of its own: it is addressed by the directory it lives in.
 */
final class AdminViewModeLatchFile
{
    /** Basename of the latch inside the log root; not `*.log`, so no log walk sees it. */
    public const string FILE_NAME = '.hilos-admin-view-mode-latch.json';

    /**
     * Basename prefix of the temp file the latch is published from, in the same directory.
     * Visible so an interrupted write leftover is recognisable as its own.
     */
    public const string TEMP_PREFIX = '.tmp-admin-view-mode-latch-';

    /** Key of the record under which the environment that closed the mode is carried. */
    public const string KEY_ENVIRONMENT = 'environment';

    /** Key of the record under which the node that closed the mode is carried. */
    public const string KEY_NODE = 'node';

    /** Key of the record under which the Unix time the mode was closed at is carried. */
    public const string KEY_CLOSED_AT = 'closedAt';

    /** Shape of the file this build writes; any other shape still reads as a latch. */
    private const int FORMAT_VERSION = 1;

    /** File key carrying {@see FORMAT_VERSION}. */
    private const string KEY_VERSION = 'version';

    /**
     * Names the latch file of one log directory.
     *
     * @param string $logRoot Absolute path of the log directory
     * @return string Absolute path of the latch file
     */
    public static function pathIn(string $logRoot): string
    {
        return $logRoot . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /**
     * Reads the latch recorded in this log directory, if one is there.
     *
     * Null only when the file is absent. A file that cannot be read, is not JSON, carries another
     * version or lacks a field is a latch all the same, and comes back as the asking node's
     * environment and node closed now - the record the other half is written back from then.
     *
     * @param string $logRoot Absolute path of the log directory
     * @param string $environment APP_ENV of the process that is asking
     * @param string $node CLUSTER_NODE_ID of the process that is asking
     * @return ?array{environment: string, node: string, closedAt: int} Latch record, or null when no file is there
     */
    public static function read(string $logRoot, string $environment, string $node): ?array
    {
        $unreadable = [
            self::KEY_ENVIRONMENT => $environment,
            self::KEY_NODE => $node,
            self::KEY_CLOSED_AT => time(),
        ];

        try {
            $raw = FsPath::read(self::pathIn($logRoot));
        } catch (FileNotFoundException) {
            return null;
        } catch (FileReadException) {
            return $unreadable;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $unreadable;
        }

        if (!is_array($decoded) || ($decoded[self::KEY_VERSION] ?? null) !== self::FORMAT_VERSION) {
            return $unreadable;
        }

        $closedEnvironment = $decoded[self::KEY_ENVIRONMENT] ?? null;
        $closedNode = $decoded[self::KEY_NODE] ?? null;
        $closedAt = $decoded[self::KEY_CLOSED_AT] ?? null;
        if (!is_string($closedEnvironment) || !is_string($closedNode) || !is_int($closedAt)) {
            return $unreadable;
        }

        return [
            self::KEY_ENVIRONMENT => $closedEnvironment,
            self::KEY_NODE => $closedNode,
            self::KEY_CLOSED_AT => $closedAt,
        ];
    }

    /**
     * Writes the latch into the log directory, atomically.
     *
     * The temp file is created beside the latch rather than in the system temp directory, because
     * a rename is only atomic within one filesystem and the log root may well live on another.
     *
     * @param string $logRoot Absolute path of the log directory
     * @param string $environment APP_ENV of the installation the mode was closed on
     * @param string $node CLUSTER_NODE_ID of the node that closed it
     * @param int $closedAt Unix time the mode was closed at
     * @throws FileMoveException When the written latch cannot be renamed over its final name
     * @throws FileWriteException When the latch cannot be written into the log directory
     * @throws JsonException From {@see json_encode()} with {@see JSON_THROW_ON_ERROR}
     */
    public static function publish(string $logRoot, string $environment, string $node, int $closedAt): void
    {
        $temporaryPath = $logRoot . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . getmypid() . '.json';
        FsPath::write($temporaryPath, json_encode(
            [
                self::KEY_VERSION => self::FORMAT_VERSION,
                self::KEY_ENVIRONMENT => $environment,
                self::KEY_NODE => $node,
                self::KEY_CLOSED_AT => $closedAt,
            ],
            JSON_THROW_ON_ERROR,
        ));
        FsPath::publish($temporaryPath, self::pathIn($logRoot));
    }
}
