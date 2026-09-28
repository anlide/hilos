<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonApplication;
use Hilos\Core\Daemon\DockerApplication;
use Hilos\Core\Daemon\DockerManager;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
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
 * Under docker the directory is claimed first by the container watchdog, from
 * {@see DockerApplication::run()}: before the startup rotation, before the watchdog takes its
 * error address, before the daemon's raw output pair is opened. The daemon's own refusal
 * could not keep the directory clean there - under the watchdog its stdout/stderr is that
 * raw pair inside the directory itself, and by the time it refused the watchdog had already
 * rotated the directory and written into it (HIL-1130).
 *
 * The daemon claims again from {@see DaemonApplication::run()}, once, after the required
 * environment is present and before {@see Logger} is pointed at the log files. Under the
 * watchdog that is a refresh of the same pair; without one it is the only claim. Nothing
 * composes until it returns: no server binds, no port is taken, no peer sees a node that is
 * not going to come up.
 *
 * A directory that does not exist yet is created by the claim, since whichever process claims
 * first may be the first to touch it. The worker inherits a decision the daemon already made,
 * and the CLI is where a stand is repaired - a gate there would be a dead end with no way out
 * of it.
 */
final class LogRootOwnershipGuard
{
    /**
     * Mode an absent log directory is created with.
     *
     * One value for both hands that may create it - this claim, and {@see DockerManager} when
     * it opens the daemon's raw output pair - so neither opens the directory wider than the other.
     */
    public const int LOG_ROOT_MODE = 0700;

    /**
     * Claims this process as the owner of the log directory, or refuses the start.
     *
     * Three outcomes: no marker — this process publishes one and starts; the marker
     * names this same environment and node — the timestamp is refreshed and the start
     * continues; any other marker — the start is refused. An absent directory has no
     * marker, and is created before one is published into it.
     *
     * @throws DirectoryCreateException When the log directory is absent and cannot be created
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

        FsPath::ensureDirectory($logRoot, self::LOG_ROOT_MODE);
        LogRootOwnerMarker::publish($logRoot, $environment, $node);
    }
}
