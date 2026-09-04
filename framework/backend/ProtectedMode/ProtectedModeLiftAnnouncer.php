<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\Utils\Logger;
use Throwable;

/**
 * ProtectedModeLiftAnnouncer - holds the "the freeze lifted, reload" frame until the logins a
 * restore carried are back in the database (HIL-771).
 *
 * The frame {@see DaemonProtectedModeExecutor::enterInactive()} broadcasts means "reload", and a
 * browser that reloads is asked for its session token. After a restore that token only exists
 * because the restore photographed it before the swap and the sessions library re-created the row
 * afterwards ({@see DeferredSessionCarryoverQueue}) - so a reload announced ahead of that pass logs
 * people out of a system that could have kept them. The window is narrow and real: the library
 * comes back with the other agents, and an operator who opens the node in that moment beats it.
 *
 * **What it waits on is a debt, not a guess.** The restore says it left logins here
 * ({@see noteSessionsDeferred()}) and the library says they are back ({@see noteSessionsCarriedOver()}),
 * both as frames from their own worker to their own master. Nothing is inferred from the freeze row
 * or from a file on disk: a node that ran no restore takes on no debt and lifts with no delay at
 * all, which is what a cluster follower and every non-restore freeze do.
 *
 * **The wait is bounded and it never blocks.** The master may not sit in a loop, so the lift is
 * held as a frame and let go from {@see DaemonManager}'s iteration - by the answer when it comes,
 * by {@see SESSIONS_WAIT_SECONDS} when it does not. A deadline that passes still lifts: a node held
 * shut over an answer that is not coming is worse than a browser that has to sign in again, and the
 * log line says which of the two happened.
 *
 * **The debt is per freeze.** {@see forgetSessionsOwed()} runs when a freeze begins, so a debt no
 * one ever answered for cannot make the NEXT lift wait for a restore that has been over for days.
 */
final class ProtectedModeLiftAnnouncer
{
    /**
     * @var int Seconds the lift waits for the restored logins before going out anyway
     *
     * Scaled to what is being waited for - one agent coming up and writing a handful of rows - and
     * not to the restore behind it, which is over by the time anything here runs. Long enough that
     * an ordinary start wins it comfortably, short enough that an operator watching the stub does
     * not read the node as hung.
     */
    private const int SESSIONS_WAIT_SECONDS = 10;

    /** @var string Agent id this announcer's own log lines are filed under */
    private const string LOG_AGENT_ID = 'protected-mode-lift';

    /** @var int Logins a restore left on this node and the sessions library has not reported back */
    private int $sessionsOwed = 0;

    /** @var ?ProtectedModeStateSignalData Lift frame waiting for those logins, or null when none waits */
    private ?ProtectedModeStateSignalData $held = null;

    /** @var int Epoch seconds after which the held frame goes out regardless */
    private int $deadline = 0;

    /**
     * Drops any debt left over from an earlier freeze, as this node enters a new one.
     *
     * The one thing that must not be inherited: a restore whose library never reported would
     * otherwise make every later lift on this node pause and complain about logins nobody is
     * waiting for. A freeze beginning is the moment the question resets, because the only writer
     * of the debt runs inside the freeze that follows.
     */
    public function forgetSessionsOwed(): void
    {
        $this->sessionsOwed = 0;
    }

    /**
     * Records that a restore left logins on this node for the sessions library to re-create.
     *
     * @param int $sessions Logins the restore queued
     */
    public function noteSessionsDeferred(int $sessions): void
    {
        if ($sessions <= 0) {
            return;
        }

        $this->sessionsOwed = $sessions;
    }

    /**
     * Records that the owed logins have been dealt with, and lets go of a lift held for them.
     *
     * "Dealt with" and not "restored": the library reports a failed pass too, because what the
     * lift is waiting on is whether anything more is coming.
     *
     * @param int $carried Logins written into the restored database
     * @param int $dropped Logins that will not survive the restore
     * @param int $kept Logins that came back inside the archive
     */
    public function noteSessionsCarriedOver(int $carried, int $dropped, int $kept): void
    {
        $this->sessionsOwed = 0;

        if ($this->held === null) {
            return;
        }

        Logger::logAgentInfo(
            self::LOG_AGENT_ID,
            "Lift held for the restored logins, released after {$carried} carried, {$kept} kept and {$dropped} dropped",
        );
        $this->release();
    }

    /**
     * Takes the lift frame when logins are still owed here, leaving the caller to send it when not.
     *
     * @param ProtectedModeStateSignalData $state Lift frame the executor would broadcast
     * @param int $now Epoch seconds, as the caller reads them
     * @return bool True when this announcer took the frame and will send it later
     */
    public function holdLift(ProtectedModeStateSignalData $state, int $now): bool
    {
        if ($this->sessionsOwed === 0) {
            return false;
        }

        Logger::logAgentInfo(
            self::LOG_AGENT_ID,
            "Lift held: {$this->sessionsOwed} restored login(s) are not back yet",
        );

        $this->held = $state;
        $this->deadline = $now + self::SESSIONS_WAIT_SECONDS;

        return true;
    }

    /**
     * Lets go of a frame whose wait has run out, from the daemon's own iteration.
     *
     * @param int $now Epoch seconds, as the loop reads them
     */
    public function tick(int $now): void
    {
        if ($this->held === null || $now < $this->deadline) {
            return;
        }

        Logger::logAgentError(
            self::LOG_AGENT_ID,
            "Lift announced without the restored logins: {$this->sessionsOwed} login(s) were not "
            . 'reported back within ' . self::SESSIONS_WAIT_SECONDS . 's, the people holding them '
            . 'will be signed out',
        );
        $this->sessionsOwed = 0;
        $this->release();
    }

    /**
     * Broadcasts the held frame and forgets it.
     *
     * Contained for the same reason {@see DaemonProtectedModeExecutor::persistFreeze()} is: this
     * runs on the master loop and on the master's frame-arrival path, and a throw from either would
     * take down a node whose freeze has already lifted - the row says inactive and the agents are
     * back, so what a failure here costs is a browser that reloads on its own instead of being told to.
     */
    private function release(): void
    {
        $state = $this->held;
        $this->held = null;
        $this->deadline = 0;
        if ($state === null) {
            return;
        }

        try {
            Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState($state, null, null);
        } catch (Throwable $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "The lift could not be announced to this node's browsers: {$e->getMessage()}",
            );
        }
    }
}
