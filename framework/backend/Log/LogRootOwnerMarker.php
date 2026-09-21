<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\LogStreamConstants;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
use Hilos\Utils\Exception\LogRootOwnedByAnotherException;
use JsonException;

/**
 * The durable claim that one daemon owns this log directory (HIL-1083).
 *
 * A marker file in the log root, not a row: the claim belongs to a directory on one
 * machine, and deleting the directory takes the claim with it. The write is the same
 * idiom as {@see LogBatchTakeoutMarker}: a temp file beside it, then a rename, so a
 * walk in progress never reads half a marker.
 *
 * The basename deliberately does NOT end in `.log`. The store walk globs `*.log`
 * ({@see LogStreamConstants::LIVE_STREAM_GLOB}), so a marker named otherwise is counted
 * in no file count and weighed in no batch weight.
 *
 * Static because a marker has no state of its own: it is addressed by the directory it
 * lives in, the way {@see FsPath} addresses a file by its path.
 */
final class LogRootOwnerMarker
{
    /** Basename of the owner marker inside the log root; not `*.log`, so no walk counts it. */
    public const string FILE_NAME = '.hilos-log-root-owner.json';

    /**
     * Basename prefix of the temp file the marker is published from, in the same directory.
     * Visible so an interrupted write leftover is recognisable as its own.
     */
    public const string TEMP_PREFIX = '.tmp-log-root-owner-';

    /**
     * Shape of the file this build writes and accepts.
     *
     * Read back and refused when it differs: a build that does not understand what it finds
     * must say so rather than treat the directory as unclaimed.
     */
    private const int FORMAT_VERSION = 1;

    /** File key carrying {@see FORMAT_VERSION}. */
    private const string KEY_VERSION = 'version';

    /** File key carrying the owner's APP_ENV. */
    private const string KEY_ENVIRONMENT = 'environment';

    /** File key carrying the owner's CLUSTER_NODE_ID. */
    private const string KEY_NODE = 'node';

    /** File key carrying the Unix timestamp of the last claim. */
    private const string KEY_STARTED_AT = 'startedAt';

    /**
     * Names the owner marker of one log directory.
     *
     * @param string $logRoot Absolute path of the log directory
     * @return string Absolute path of the marker file
     */
    public static function pathIn(string $logRoot): string
    {
        return $logRoot . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /**
     * Reads the owner recorded in this log directory, if one is there.
     *
     * Null only when the file is absent. A file that cannot be read, is not JSON, carries
     * another version, or lacks any of the three owner fields is foreign, not missing —
     * opening the directory on the strength of a parse failure would lose the claim.
     *
     * The arriving process's environment and node are taken so an unreadable refusal can
     * name who was turned away; they are not used to judge the file.
     *
     * @param string $logRoot Absolute path of the log directory
     * @param string $environment APP_ENV of the process that is asking
     * @param string $node CLUSTER_NODE_ID of the process that is asking
     * @return ?array{environment: string, node: string, startedAt: int} Owner record, or null when no marker is there
     * @throws LogRootOwnedByAnotherException When a marker is there and cannot be understood
     */
    public static function read(string $logRoot, string $environment, string $node): ?array
    {
        try {
            $raw = FsPath::read(self::pathIn($logRoot));
        } catch (FileNotFoundException) {
            return null;
        } catch (FileReadException) {
            throw LogRootOwnedByAnotherException::forUnreadable($logRoot, $environment, $node);
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw LogRootOwnedByAnotherException::forUnreadable($logRoot, $environment, $node);
        }

        if (!is_array($decoded) || ($decoded[self::KEY_VERSION] ?? null) !== self::FORMAT_VERSION) {
            throw LogRootOwnedByAnotherException::forUnreadable($logRoot, $environment, $node);
        }

        $ownerEnvironment = $decoded[self::KEY_ENVIRONMENT] ?? null;
        $ownerNode = $decoded[self::KEY_NODE] ?? null;
        $startedAt = $decoded[self::KEY_STARTED_AT] ?? null;
        if (!is_string($ownerEnvironment) || !is_string($ownerNode) || !is_int($startedAt)) {
            throw LogRootOwnedByAnotherException::forUnreadable($logRoot, $environment, $node);
        }

        return [
            self::KEY_ENVIRONMENT => $ownerEnvironment,
            self::KEY_NODE => $ownerNode,
            self::KEY_STARTED_AT => $startedAt,
        ];
    }

    /**
     * Writes this process as the owner of the log directory, atomically.
     *
     * The temp file is created beside the marker rather than in the system temp directory,
     * because a rename is only atomic within one filesystem and the log root may well live
     * on another. The pid is part of its name so two claims racing on one node cannot
     * overwrite each other's half-written file.
     *
     * @param string $logRoot Absolute path of the log directory
     * @param string $environment APP_ENV of this process
     * @param string $node CLUSTER_NODE_ID of this process
     * @throws FileMoveException When the written marker cannot be renamed over its final name
     * @throws FileWriteException When the marker cannot be written into the log directory
     * @throws JsonException From {@see json_encode()} with {@see JSON_THROW_ON_ERROR}
     */
    public static function publish(string $logRoot, string $environment, string $node): void
    {
        $temporaryPath = $logRoot . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . getmypid() . '.json';
        FsPath::write($temporaryPath, json_encode(
            [
                self::KEY_VERSION => self::FORMAT_VERSION,
                self::KEY_ENVIRONMENT => $environment,
                self::KEY_NODE => $node,
                self::KEY_STARTED_AT => time(),
            ],
            JSON_THROW_ON_ERROR,
        ));
        FsPath::publish($temporaryPath, self::pathIn($logRoot));
    }
}
