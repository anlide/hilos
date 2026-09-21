<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonApplication;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Hilos;
use Hilos\Utils\Exception\LogRootOwnedByAnotherException;
use Hilos\Utils\Logger;
use JsonException;

/**
 * LogRootOwnershipGuard - the log-directory claim asked at the startup of a node.
 *
 * A node's log directory belongs to one daemon. Two daemons never share one: not two
 * environments of the same project, and not two nodes of one environment. The claim is
 * a marker in the directory, and a marker that names another owner, or that cannot be
 * read, refuses the start.
 *
 * The reader of a refusal is the operator of the stand, not the author of a migration:
 * the only lawful hand-over is to delete the marker, and that operator is the one who
 * can. So the refusal speaks on stdout/stderr (`docker logs`) rather than in the journal
 * of the directory it is being turned away from.
 *
 * Runs from {@see DaemonApplication::run()}, once, after the required environment is
 * present and before {@see Logger} is pointed at the log files. Nothing composes until
 * it returns: no server binds, no port is taken, no peer sees a node that is not going
 * to come up.
 *
 * Only the daemon carries it. The worker inherits a decision the daemon already made,
 * and the CLI is where a stand is repaired - a gate there would be a dead end with no
 * way out of it.
 */
final class LogRootOwnershipGuard
{
    /**
     * Claims this process as the owner of the log directory, or refuses the start.
     *
     * Three outcomes: no marker — this process publishes one and starts; the marker
     * names this same environment and node — the timestamp is refreshed and the start
     * continues; any other marker — the start is refused.
     *
     * @throws EnvException When APP_ENV, CLUSTER_NODE_ID, or DAEMON_LOG_FILE cannot be read
     * @throws FileMoveException When the owner marker cannot be published into place
     * @throws FileWriteException When the owner marker cannot be written
     * @throws JsonException When the owner marker cannot be encoded
     * @throws LogRootOwnedByAnotherException When the directory already belongs to another daemon
     */
    public static function claimLogRoot(): void
    {
        $logRoot = dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]->string());
        $environment = Hilos::$env[EnvConstants::APP_ENV]->string();
        $node = Hilos::$env[EnvConstants::CLUSTER_NODE_ID]->string();
        $owner = LogRootOwnerMarker::read($logRoot, $environment, $node);
        if ($owner !== null
            && ($owner['environment'] !== $environment || $owner['node'] !== $node)
        ) {
            throw LogRootOwnedByAnotherException::forOwner(
                $logRoot,
                $owner['environment'],
                $owner['node'],
                $environment,
                $node,
            );
        }

        LogRootOwnerMarker::publish($logRoot, $environment, $node);
    }
}
