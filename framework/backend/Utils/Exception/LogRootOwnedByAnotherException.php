<?php

declare(strict_types=1);

namespace Hilos\Utils\Exception;

use Hilos\HilosException;
use Hilos\Log\LogRootOwnerMarker;
use Hilos\Log\LogRootOwnershipGuard;

/**
 * The log directory already has an owner, and this daemon is not it (HIL-1083).
 *
 * Raised at startup by {@see LogRootOwnershipGuard} when the marker names another
 * environment or node, and by {@see LogRootOwnerMarker::read()} when a marker is
 * there and cannot be understood. An unreadable file is treated as foreign: opening
 * the directory on the strength of a parse failure would lose the claim the file
 * exists to keep. A missing marker is not this — that directory has no owner yet.
 *
 * The message names both sides and the only lawful hand-over: delete the marker.
 */
final class LogRootOwnedByAnotherException extends HilosException
{
    /**
     * Creates the refusal for a marker whose owner pair does not match this process.
     *
     * @param string $logRoot Absolute path of the log directory
     * @param string $ownerEnvironment APP_ENV recorded in the marker
     * @param string $ownerNode CLUSTER_NODE_ID recorded in the marker
     * @param string $environment APP_ENV of the process that arrived
     * @param string $node CLUSTER_NODE_ID of the process that arrived
     * @return self Exception instance
     */
    public static function forOwner(
        string $logRoot,
        string $ownerEnvironment,
        string $ownerNode,
        string $environment,
        string $node,
    ): self {
        $markerPath = LogRootOwnerMarker::pathIn($logRoot);

        return new self(
            "This daemon refuses to start: the log directory {$logRoot} belongs to"
            . " environment \"{$ownerEnvironment}\" node \"{$ownerNode}\", and this process is"
            . " environment \"{$environment}\" node \"{$node}\". If the directory is being handed"
            . " to this environment on purpose, delete {$markerPath} and start again.",
        );
    }

    /**
     * Creates the refusal for a marker that is there and cannot be understood.
     *
     * @param string $logRoot Absolute path of the log directory
     * @param string $environment APP_ENV of the process that arrived
     * @param string $node CLUSTER_NODE_ID of the process that arrived
     * @return self Exception instance
     */
    public static function forUnreadable(string $logRoot, string $environment, string $node): self
    {
        $markerPath = LogRootOwnerMarker::pathIn($logRoot);

        return new self(
            "This daemon refuses to start: the log-root owner marker at {$markerPath} cannot be"
            . " read, so this process treats the directory {$logRoot} as belonging to another"
            . " owner. This process is environment \"{$environment}\" node \"{$node}\". If the"
            . " directory is being handed to this environment on purpose, delete {$markerPath}"
            . ' and start again.',
        );
    }
}
