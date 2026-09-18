<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\HilosAgentType;
use Hilos\Utils\Logger;

/**
 * RestoreReleaseGate - holds the request that would lift the freeze until the logins a restore
 * carried are back in the database (HIL-969).
 *
 * A lift asked for ahead of that pass signs people out of a system that could have kept them:
 * the token a reload presents only exists because the restore photographed it and the sessions
 * library re-created the row afterwards. The window is narrow and real: the library comes back
 * with the other agents, and an operator who opens the node in that moment beats it.
 *
 * **What it waits on is a debt, not a guess.** The restore says it left logins here
 * ({@see noteSessionsDeferred()}) and the same agent says they are back
 * ({@see noteSessionsCarriedOver()}) once the library has answered for them. A node that ran no
 * restore takes on no debt and lifts with no delay at all.
 *
 * **The wait is bounded and it never blocks.** The request is parked and let go from the agent's
 * own tick - by the answer when it comes, by {@see SESSIONS_WAIT_SECONDS} when it does not. A
 * deadline that passes still lifts: a node held shut over an answer that is not coming is worse
 * than a browser that has to sign in again, and the log line says which of the two happened.
 *
 * **A debt nobody will answer is not waited for.** {@see holdRelease()} parks only when an owner
 * in this project can still receive the batch; otherwise the caller sends the lift at once.
 *
 * **The debt is per freeze.** {@see forgetSessionsOwed()} runs when a freeze begins, so a debt no
 * one ever answered for cannot make the NEXT lift wait for a restore that has been over for days.
 *
 * Kept out of the agent so the mechanism runs without one: a unit of this class does not raise a
 * restore with a child process.
 */
final class RestoreReleaseGate
{
    /**
     * @var float Seconds the release waits for the restored logins before going out anyway
     *
     * Must expire inside {@see CommandChannelWindows::AGENT_WAIT_SECONDS} (13.0). The operator's
     * terminal is waiting on that window for inactive; raising this wait to 15 would cost them a
     * silent channel timeout instead of the reason this class writes when the logins never come
     * back.
     *
     * Scaled to what is being waited for - one agent coming up and writing a handful of rows - and
     * not to the restore behind it, which is over by the time anything here runs. Long enough that
     * an ordinary start wins it comfortably, short enough that an operator watching the stub does
     * not read the node as hung.
     */
    private const float SESSIONS_WAIT_SECONDS = 10.0;

    /** @var string Agent id this gate's own log lines are filed under */
    private const string LOG_AGENT_ID = HilosAgentType::HILOS_BACKUP;

    /** @var int Logins a restore left on this node and the sessions library has not reported back */
    private int $sessionsOwed = 0;

    /** @var bool True when a release request is parked for those logins */
    private bool $releaseHeld = false;

    /** @var float Timestamp after which the parked request goes out regardless */
    private float $deadline = 0.0;

    /** @var int Logins the last receipt wrote into the restored database */
    private int $lastCarried = 0;

    /** @var int Logins the last receipt dropped */
    private int $lastDropped = 0;

    /** @var int Logins the last receipt said came back inside the archive */
    private int $lastKept = 0;

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
     * Records that the owed logins have been dealt with. Does not let go of a parked request.
     *
     * "Dealt with" and not "restored": the library reports a failed pass too, because what the
     * release is waiting on is whether anything more is coming. The caller asks
     * {@see releaseDue()} on the same turn if a request is parked for them.
     *
     * @param int $carried Logins written into the restored database
     * @param int $dropped Logins that will not survive the restore
     * @param int $kept Logins that came back inside the archive
     */
    public function noteSessionsCarriedOver(int $carried, int $dropped, int $kept): void
    {
        $this->sessionsOwed = 0;
        $this->lastCarried = $carried;
        $this->lastDropped = $dropped;
        $this->lastKept = $kept;
    }

    /**
     * Drops any debt left over from an earlier freeze, as this node enters a new one.
     *
     * The one thing that must not be inherited: a restore whose library never reported would
     * otherwise make every later lift on this node pause and complain about logins nobody is
     * waiting for. A freeze beginning is the moment the question resets, because the only writer
     * of the debt runs inside the freeze that follows. A request parked for that forgotten debt
     * is dropped rather than sent: the new freeze is the one that owns the node now.
     */
    public function forgetSessionsOwed(): void
    {
        $this->sessionsOwed = 0;
        $this->clearHold();
    }

    /**
     * Parks the release request when logins are still owed here and an owner can still answer.
     *
     * A second call while a request is already parked does not reset the deadline: the wait
     * started when the first caller asked.
     *
     * @param float $now Current time in seconds, as the caller reads it
     * @param bool $ownerWillAnswer False when no agent of this project receives the sessions queue
     * @return bool True when this gate took the request and the caller must not send it yet
     */
    public function holdRelease(float $now, bool $ownerWillAnswer): bool
    {
        if ($this->releaseHeld) {
            Logger::logAgentInfo(
                self::LOG_AGENT_ID,
                'Release already held for the restored logins',
            );

            return true;
        }

        if ($this->sessionsOwed === 0) {
            return false;
        }

        if (!$ownerWillAnswer) {
            Logger::logAgentInfo(
                self::LOG_AGENT_ID,
                "Release sent without waiting: {$this->sessionsOwed} login(s) stay on disk, "
                . 'nobody in this project receives them',
            );

            return false;
        }

        Logger::logAgentInfo(
            self::LOG_AGENT_ID,
            "Release held: {$this->sessionsOwed} restored login(s) are not back yet",
        );

        $this->releaseHeld = true;
        $this->deadline = $now + self::SESSIONS_WAIT_SECONDS;

        return true;
    }

    /**
     * Whether a parked request must go out now: the debt is paid, or the wait has run out.
     *
     * Lets go of the parking in either case, so a second call does not send the lift twice.
     *
     * @param float $now Current time in seconds, as the loop reads it
     * @return bool True when the caller must send the parked request now
     */
    public function releaseDue(float $now): bool
    {
        if (!$this->releaseHeld) {
            return false;
        }

        if ($this->sessionsOwed === 0) {
            Logger::logAgentInfo(
                self::LOG_AGENT_ID,
                "Release held for the restored logins, sent after {$this->lastCarried} carried, "
                . "{$this->lastKept} kept and {$this->lastDropped} dropped",
            );
            $this->clearHold();

            return true;
        }

        if ($now < $this->deadline) {
            return false;
        }

        Logger::logAgentError(
            self::LOG_AGENT_ID,
            "Release sent without the restored logins: {$this->sessionsOwed} login(s) were not "
            . 'reported back within ' . self::SESSIONS_WAIT_SECONDS . 's, the people holding them '
            . 'will be signed out',
        );
        $this->sessionsOwed = 0;
        $this->clearHold();

        return true;
    }

    /**
     * Drops a parked request without sending it.
     */
    private function clearHold(): void
    {
        $this->releaseHeld = false;
        $this->deadline = 0.0;
    }
}
