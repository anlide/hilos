<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DockerApplication;
use Hilos\Core\Daemon\DockerManager;
use Hilos\Hilos;

/**
 * The one place that answers what a daemon log address is when the environment names none (HIL-843).
 *
 * The container watchdog owns neither address: both belong to the daemon, which refuses on its own
 * and names every missing value at once (HIL-734). Read outright, either of them refuses one name
 * earlier and from the wrong process, and the operator learns the rest of the list one restart at a
 * time. So the address is read unassertively here, and each caller decides for itself what an
 * unset one means to it.
 *
 * Four places on the watchdog's path need that answer: {@see DockerApplication} configuring the
 * Logger, {@see DockerManager} announcing the gap, skipping rotation, pointing the child's
 * descriptors and quoting the error tail. Three or four of them asking the environment separately
 * would part ways on the first edit — the same argument {@see DaemonRawStream} was built on — and a
 * private helper could not be unit-tested, which on this leaf is the only thing testable without a
 * container.
 */
final class DaemonLogAddress
{
    /**
     * Reads a daemon log address without refusing over it.
     *
     * @param EnvConstants $name Env name of one of the two daemon log streams
     * @return ?string Address the environment gives that stream, or null when it names none
     */
    public static function configured(EnvConstants $name): ?string
    {
        $env = Hilos::$env;
        if ($env === null) {
            return null;
        }

        // isset() rather than a plain read: EnvAccessor::offsetExists() swallows the refusal these
        // required names would raise, which is the whole point of asking from here.
        return isset($env[$name]) ? $env[$name] : null;
    }

    /**
     * Names the addresses the environment leaves unset, and says nothing when there is no
     * environment at all: a process that never captured one is not an installation missing a
     * line in .env, and listing both names there would complain about the wrong thing.
     *
     * @return list<string> Env names of the daemon log addresses left unset, output stream first
     */
    public static function missingNames(): array
    {
        if (Hilos::$env === null) {
            return [];
        }

        $missing = [];

        foreach ([EnvConstants::DAEMON_LOG_FILE, EnvConstants::DAEMON_ERROR_LOG_FILE] as $name) {
            if (self::configured($name) === null) {
                $missing[] = $name->name;
            }
        }

        return $missing;
    }
}
