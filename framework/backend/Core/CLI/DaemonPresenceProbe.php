<?php

declare(strict_types=1);

namespace Hilos\Core\CLI;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * DaemonPresenceProbe - asks the command channel whether a daemon is answering, and nothing else.
 *
 * One TCP connect against `HILOS_DAEMON_HOST:COMMAND_PORT` with a short budget. It sends no
 * request and reads no reply: an accepted connection already answers the only question asked,
 * and a probe that spoke the protocol would be a command of its own - queued behind whatever the
 * daemon is doing, and slow exactly when the daemon is busy.
 *
 * The budget is small on purpose. This runs in front of migrations and fixtures, on a path an
 * operator waits on; a probe that took a visible moment would be paid on every such command, and
 * a daemon that is up accepts a local connection in microseconds. A daemon too loaded to accept
 * inside the budget reads as DOWN, which is the direction that costs a refusal rather than a
 * second writer.
 */
final class DaemonPresenceProbe
{
    /** @var float Seconds to wait for the connect before calling the channel silent */
    private const float CONNECT_TIMEOUT_SECONDS = 0.3;

    /**
     * Reports whether a daemon answers on this installation's command channel.
     *
     * @return DaemonPresence UP when the connect is accepted, DOWN when refused, UNKNOWN when unaskable
     */
    public static function probe(): DaemonPresence
    {
        $address = self::address();
        if ($address === null) {
            return DaemonPresence::UNKNOWN;
        }

        $errno = 0;
        $errstr = '';
        // warning-suppressed: a refused connect is the DOWN answer, not an error to report; $socket is checked next
        $socket = @stream_socket_client(
            "tcp://{$address}",
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
        );

        if (!is_resource($socket)) {
            return DaemonPresence::DOWN;
        }

        fclose($socket);

        return DaemonPresence::UP;
    }

    /**
     * The command channel's address, or null when the environment does not name one.
     *
     * Null rather than a thrown failure because both callers want the same thing from it - the
     * probe turns it into {@see DaemonPresence::UNKNOWN}, and the refusal text needs an address
     * only in the branch where one exists.
     *
     * @return ?string host:port of the command channel, or null when either env value is missing or invalid
     */
    public static function address(): ?string
    {
        try {
            $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
            $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);
        } catch (EnvException) {
            return null;
        }

        return "{$host}:{$port}";
    }
}
