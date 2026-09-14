<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Daemon\DaemonApplication;
use Hilos\Hilos;
use Hilos\ProtectedMode\Exception\SessionlessConnectionsRosterException;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\View\Context\RtContext;

/**
 * SessionStageStartupGuard - the session-stage question asked at the startup of a node.
 *
 * Protected mode recognizes an initiator, a verifier, and a photographed circle member by
 * the browser session behind each connection. A project that mounts its browser connections
 * on the presence stage has no session token on those rows, so the worker refuses every page
 * subscription even after the master admitted the same browser. Refusing that wiring at
 * startup keeps the two halves of the gate from disagreeing on a live node.
 *
 * The guard reads only the in-memory collection map of the project's {@see RtContext}. It
 * runs from {@see DaemonApplication::run()} after the constant-only set-ownership guard and
 * before the anonymization guard that queries the live schema. Only the daemon carries it,
 * before anything composes or binds a server.
 *
 * There is no loop and no list of findings: an installation mounts at most one framework-
 * based connections roster, so this guard can report exactly one fault. It stays silent when
 * the process has no runtime context, when the context mounts no browser connections, and
 * when that roster already stands on the session stage.
 */
final class SessionStageStartupGuard
{
    /**
     * Refuses a node whose browser connections roster carries no session token.
     *
     * @throws SessionlessConnectionsRosterException When browser connections use the presence stage
     */
    public static function assertRosterCarriesSessions(): void
    {
        $rt = Hilos::$rt;
        if ($rt === null) {
            return;
        }

        $roster = $rt->connectionsSource();
        if ($roster === null) {
            return;
        }

        if ($rt->sessionConnectionsSource() !== null) {
            return;
        }

        throw new SessionlessConnectionsRosterException(
            'This node serves browsers and refuses to start with its connections roster on the presence stage: '
            . $roster::class . ' (mounted by ' . $rt::class . ') extends '
            . HilosConnections::class . ' and has to extend ' . HilosSessionConnections::class
            . ' instead - protected mode recognizes the initiator, a verifier and a circle member by the browser '
            . 'session behind the connection, and only the session stage carries a session token to recognize them by.',
        );
    }
}
