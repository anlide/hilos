<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\ProtectedMode\ClusterProtectedMode;

/**
 * ReHydrateRound - the barrier a database swap has to close before the node is opened again (HIL-436).
 *
 * The announcement of a swap used to be fire-and-forget: whoever replaced the database told
 * everybody to re-read and immediately moved on. This is the counter: the process that knows the
 * roster - the daemon for its workers, the leader for its nodes - opens a round over that roster,
 * collects one answer per participant and reports a single aggregated verdict.
 *
 * The shape mirrors {@see ClusterProtectedMode}'s quiesce round, with one difference that is the
 * whole reason this class exists separately: a quiesce round has no deadline, because a freeze that
 * never completes simply never opens anything, while a re-hydrate round guards the step *after* the
 * destructive work and therefore has to end - in a verdict the operator can read - even when a
 * participant goes quiet. Hence {@see expire()}.
 *
 * Pure state, no I/O: it is driven from {@see DaemonManager}'s existing loop and never touches a
 * socket, which is also what makes the whole barrier unit-testable without a stand.
 *
 * Two predicates rather than one, and they answer different questions:
 * - {@see isSettled()} - nobody is left to wait for, so the verdict can be sent;
 * - {@see isComplete()} - every participant answered, and answered positively.
 *
 * A negative answer settles the round without completing it, on purpose: a process that failed to
 * re-read holds caches of a database that no longer exists, and letting a verifier in to read those
 * would confirm a fiction. Fail-closed is the same choice the freeze entry already makes.
 */
final class ReHydrateRound
{
    /** Reason recorded for a participant that answered, but could not re-read. */
    private const string REASON_READ_FAILED = 'read failed';

    /** Reason recorded for a participant that never answered before the deadline. */
    private const string REASON_TIMEOUT = 'timeout';

    /** Label of the master process itself, which re-reads its own collections like everybody else. */
    private const string PARTICIPANT_DAEMON = 'daemon';

    /** Prefix of a worker's label; the index behind it is what the operator sees in the logs. */
    private const string PARTICIPANT_WORKER_PREFIX = 'worker #';

    /** @var array<string, true> Participants that still owe an answer, keyed by label */
    private array $pending = [];

    /** @var list<string> Human-readable problems, one per participant that failed or went quiet */
    private array $problems = [];

    /** Deadline on the {@see microtime()} scale, after which the pending participants are written off. */
    private float $deadline = 0.0;

    /**
     * @return string Label of the master process as a participant
     */
    public static function daemonParticipant(): string
    {
        return self::PARTICIPANT_DAEMON;
    }

    /**
     * @param int $workerIndex Index of the worker, as it registered itself
     * @return string Label of that worker as a participant
     */
    public static function workerParticipant(int $workerIndex): string
    {
        return self::PARTICIPANT_WORKER_PREFIX . $workerIndex;
    }

    /**
     * A node answers for itself under its own id: the operator reads these labels next to the
     * node names in the cluster roster, and inventing a second vocabulary for the same thing
     * would only make the two lists impossible to line up.
     *
     * @param string $nodeId Cluster node id
     * @return string Label of that node as a participant
     */
    public static function nodeParticipant(string $nodeId): string
    {
        return $nodeId;
    }

    /**
     * Opens the round over the roster fixed at announcement time.
     *
     * The roster is a snapshot by design: a participant that appears afterwards is reading the
     * database that is already in place, so it has nothing to confirm.
     *
     * @param list<string> $participants Human-readable participant labels ('daemon', 'worker #2', 'node-b')
     * @param float $deadline Wall-clock deadline on the {@see microtime()} scale
     */
    public function start(array $participants, float $deadline): void
    {
        $this->pending = array_fill_keys($participants, true);
        $this->problems = [];
        $this->deadline = $deadline;
    }

    /**
     * Records one participant's answer.
     *
     * An answer from somebody the round is not waiting for is ignored rather than recorded: a late
     * duplicate would otherwise re-open a settled round or add a second problem line for a
     * participant that was already written off by {@see expire()}.
     *
     * @param string $participant Participant label, as passed to {@see start()}
     * @param bool $ok Whether that participant re-read its collections successfully
     * @param ?string $error Failure text when it did not, null when there is none to quote
     */
    public function ack(string $participant, bool $ok, ?string $error): void
    {
        if (!isset($this->pending[$participant])) {
            return;
        }

        unset($this->pending[$participant]);

        if ($ok) {
            return;
        }

        $reason = $error === null || $error === ''
            ? self::REASON_READ_FAILED
            : self::REASON_READ_FAILED . ': ' . $error;
        $this->problems[] = "{$participant}: {$reason}";
    }

    /**
     * Takes a participant that disappeared off the count.
     *
     * Not a problem and not a timeout: a worker whose socket closed cannot answer with a fiction,
     * and whatever starts in its place reads the database that is already in place. Waiting for it
     * would only delay the verdict by the full deadline.
     *
     * @param string $participant Participant label, as passed to {@see start()}
     */
    public function drop(string $participant): void
    {
        unset($this->pending[$participant]);
    }

    /**
     * Writes off everyone still silent once the deadline has passed.
     *
     * Called from the daemon loop on every iteration; a no-op before the deadline. Afterwards the
     * round is settled but not complete, which is what keeps the node closed.
     *
     * @param float $now Current time on the {@see microtime()} scale
     */
    public function expire(float $now): void
    {
        if ($this->pending === [] || $now < $this->deadline) {
            return;
        }

        foreach (array_keys($this->pending) as $participant) {
            $this->problems[] = $participant . ': ' . self::REASON_TIMEOUT;
        }
        $this->pending = [];
    }

    /**
     * @return bool True when no participant is left to wait for, however each of them ended
     */
    public function isSettled(): bool
    {
        return $this->pending === [];
    }

    /**
     * @return bool True when every participant answered and every answer was positive
     */
    public function isComplete(): bool
    {
        return $this->pending === [] && $this->problems === [];
    }

    /**
     * @return list<string> One line per participant that failed or went quiet, empty when complete
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
