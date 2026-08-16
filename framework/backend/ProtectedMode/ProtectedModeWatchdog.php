<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\Template\ProtectedModeClearedMailTemplate;
use Hilos\Mail\Template\ProtectedModeStuckMailTemplate;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Logger;
use Throwable;

/**
 * ProtectedModeWatchdog - notices that a node has been frozen with nothing happening behind it.
 *
 * **It never lifts a freeze.** It detects and it tells a person, and that is the whole of it. A
 * watchdog cannot tell "the initiator hung" from "the initiator is in the middle of the import",
 * and opening a node onto a half-written database is the single failure protected mode exists to
 * prevent - so lifting on a timeout would reintroduce by the back door what the operator ladder
 * removed on purpose. Not even the safe-looking case is excepted: a leader stuck in activating
 * never handed out ready and destroyed nothing, and it is still only reported. One rule, no
 * phase-by-phase branching, because the person reading the alert at 3am has to be able to predict
 * what the system did without it.
 *
 * **It runs on the master, and there is no choice about that.** During a freeze every agent but
 * the initiator is stopped, so a watchdog agent would either be stopped along with them or be the
 * very process it is watching. The master is the one process the freeze does not touch, and it
 * already owns the freeze row. {@see DaemonManager} ticks it from the main loop under the same
 * leader gate the cron check uses - a deadline only has to be noticed within one iteration - and
 * the master's own constraints carry over unweakened: this class reads memory, writes nothing at
 * all (not the row, not the database) and makes no blocking call.
 *
 * **What it keeps between ticks** is a fingerprint of the freeze it is watching (the operation and
 * the moment it began), the verdict it last reported, and the two facts that cannot be re-derived
 * from the row later: that the initiator agent stopped, and that the freeze came back from disk
 * with the daemon. Both are held against the fingerprint they belong to rather than as bare flags,
 * so a fact learned before the first tick is not lost when that tick adopts the freeze, and one
 * left over from a previous freeze can never be read as this one's. A phase of inactive drops
 * everything: there is no freeze to remember.
 *
 * **A cluster caveat worth knowing.** Only the leader watches, and the stop of an initiator agent
 * is reported to the master of the node hosting it. When the initiator sits on a follower the
 * leader never hears that stop and falls back on {@see ProtectedModeStuckVerdict::SILENT}: a dead
 * initiator stops marking progress, so the freeze is still reported, just after the threshold
 * instead of at once.
 */
final class ProtectedModeWatchdog implements ProtectedModeAgentStopSink
{
    /** @var string Agent id the watchdog's own log lines are filed under */
    private const string LOG_AGENT_ID = 'protected-mode-watchdog';

    /**
     * @var int Seconds a quiesce round may stay open when the configured timeout cannot be read
     *
     * Not the catalog default under another name: that one is the value an operator may tune, this
     * one is what a node falls back on when the tuning itself is unreadable, and the two would move
     * for different reasons.
     */
    private const int QUIESCE_TIMEOUT_FALLBACK_SECONDS = 30;

    /** @var int Seconds a freeze may stay silent when the configured timeout cannot be read */
    private const int SILENCE_TIMEOUT_FALLBACK_SECONDS = 600;

    /** @var int Seconds between reminders when the configured interval cannot be read */
    private const int ALERT_INTERVAL_FALLBACK_SECONDS = 900;

    /** @var int Seconds in a minute, for rendering a duration a person reads */
    private const int SECONDS_PER_MINUTE = 60;

    /** @var int Minutes in an hour, for rendering a duration a person reads */
    private const int MINUTES_PER_HOUR = 60;

    /** @var string What a node that can name itself neither way is called in an alert */
    private const string UNNAMED_NODE = '(unnamed node)';

    /** @var string What an operation with no name on the row is called in a log line */
    private const string UNNAMED_OPERATION = '(unnamed)';

    /**
     * @var string What an initiator the row does not name is called in a log line
     *
     * Its own constant rather than a share of {@see UNNAMED_OPERATION}: the two happen to read the
     * same today and answer different questions, so one would have to be reworded without the other.
     */
    private const string UNNAMED_INITIATOR = '(unnamed)';

    /** @var ?string Fingerprint of the freeze being watched, or null when no freeze stands */
    private ?string $freeze = null;

    /** @var int Epoch seconds this process began or inherited the round it is watching */
    private int $freezeSeenAt = 0;

    /** @var ?string Fingerprint of the freeze whose initiator agent was reported stopped */
    private ?string $initiatorLostFor = null;

    /** @var ?string Fingerprint of the freeze this process came up holding, restored from disk */
    private ?string $restoredFromDiskFor = null;

    /** @var ?ProtectedModeStuckVerdict Verdict already reported for this freeze, or null when none */
    private ?ProtectedModeStuckVerdict $reported = null;

    /** @var ?int Quiesce threshold in seconds, read from the environment once and kept */
    private ?int $quiesceTimeout = null;

    /** @var ?int Silence threshold in seconds, read from the environment once and kept */
    private ?int $silenceTimeout = null;

    /** @var ?int Reminder interval in seconds, read from the environment once and kept */
    private ?int $alertInterval = null;

    /** @var ?int Epoch seconds the alarm about this freeze was first raised, or null when none was */
    private ?int $alarmRaisedAt = null;

    /** @var ?int Epoch seconds the alarm was last sent out, or null when none was */
    private ?int $alertedAt = null;

    /** @var ?string Operation the freeze protects, kept for the all-clear the lift clears the row before */
    private ?string $alarmOperation = null;

    /** @var ?int Epoch seconds the freeze began, kept for the same reason */
    private ?int $alarmFrozenSince = null;

    /** @var ?int Epoch seconds the initiator agent was found gone, or null while it is not */
    private ?int $initiatorLostAt = null;

    /**
     * @param ProtectedModeAlertNotifier $notifier Channel the alarm goes out on, beside the log line
     */
    public function __construct(
        private readonly ProtectedModeAlertNotifier $notifier = new ProtectedModeAlertNotifier(),
    ) {
    }

    /**
     * Judges the freeze this node is under, and reports it the first time it is found stuck.
     *
     * The clock is passed in rather than read here, as {@see AgentManagerDaemon::pollReHydrateVerdict()}
     * takes it, so the thresholds are exercisable without waiting them out.
     *
     * A verdict is reported once, not once per tick: a stuck freeze holds for as long as the
     * operator takes to answer it, and a line per loop iteration would bury the alert in its own
     * repetition. What does report again is an escalation - a freeze reported silent whose
     * initiator is then found gone says so, because it is a stronger statement about the same node.
     *
     * @param int $now Epoch seconds to judge against
     */
    public function tick(int $now): void
    {
        $view = $this->freezeRow();
        if ($view === null || $view->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            $this->reportLifted($now);
            $this->forgetFreeze();

            return;
        }

        $this->adoptFreeze(self::fingerprintOf($view), $now);

        $verdict = $this->judge($view, $now);
        if ($verdict === null) {
            return;
        }

        if ($verdict === ProtectedModeStuckVerdict::INITIATOR_LOST && $this->initiatorLostAt === null) {
            // Stamped on the tick that notices rather than in the stop itself, which is the same
            // moment to within one iteration of the daemon loop and keeps every clock this class
            // reasons with arriving through one door.
            $this->initiatorLostAt = $now;
        }
        $isNews = $verdict !== $this->reported;
        if (!$isNews && !$this->reminderIsDue($now)) {
            return;
        }

        $this->reported = $verdict;
        $this->alarmRaisedAt ??= $now;
        $this->alarmOperation = $view->operation;
        $this->alarmFrozenSince = $view->startedAt ?? $this->freezeSeenAt;
        $this->alertedAt = $now;

        $problem = $this->problem($verdict, $view, $now);
        if ($isNews) {
            // Written before the mail and independently of it: it is the one report that cannot
            // fail. A reminder is not repeated here - the log already carries the alarm, and the
            // repetition exists for the mailbox, not for the file.
            Logger::logAgentError(self::LOG_AGENT_ID, self::stuckLogLine($view, $problem));
        }

        $this->notifier->notifyStuck($this->stuckParams($view, $problem, $now, still: !$isNews));
    }

    /**
     * Records that the freeze standing right now came back from disk with the daemon.
     *
     * Called by {@see DaemonManager::boot()} right after it restores the row from
     * {@see ProtectedModeFreezeStore}, which is the only place that can know this: by the time the
     * loop ticks, a restored freeze is indistinguishable from one this process established itself.
     *
     * A node that restored a freeze knows with certainty that it started no operation behind it,
     * which is why this is reported at once rather than after the silence threshold. It is filed
     * against the freeze on the row, so the first tick that adopts that freeze reads it.
     */
    public function markRestoredFromDisk(): void
    {
        $view = $this->freezeRow();
        if ($view === null || $view->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            return;
        }

        $this->restoredFromDiskFor = self::fingerprintOf($view);
    }

    /**
     * Notes a stopped agent, and remembers it when it was the one holding the freeze.
     *
     * Judged here rather than at the next tick because the answer stops being available: the
     * agent-start gate lets the initiator's type start again while the freeze stands
     * ({@see WorkerServer::protectedModeRefusesStart()}), so a later look at the roster could find
     * a fresh instance and read the freeze as healthy while the operation behind it is gone.
     *
     * @param string $agentId Id of the agent that stopped, in the `type` or `type:index` form
     */
    public function onAgentStopped(string $agentId): void
    {
        $view = $this->freezeRow();
        if ($view === null || $view->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            return;
        }
        if ($agentId !== self::initiatorAgentId($view)) {
            return;
        }

        $this->initiatorLostFor = self::fingerprintOf($view);
    }

    /**
     * Decides whether the freeze on the row is stuck, and on what grounds.
     *
     * The four cases in their evaluation order, first match winning: certainty before inference,
     * so the operator is told the strongest thing that can be said about this freeze.
     *
     * @param ProtectedModeRuntime $view Freeze row this node carries
     * @param int $now Epoch seconds to judge against
     * @return ?ProtectedModeStuckVerdict Why the freeze is stuck, or null while it is fine
     */
    private function judge(ProtectedModeRuntime $view, int $now): ?ProtectedModeStuckVerdict
    {
        if ($this->initiatorLostFor === $this->freeze) {
            return ProtectedModeStuckVerdict::INITIATOR_LOST;
        }
        if ($this->restoredFromDiskFor === $this->freeze) {
            return ProtectedModeStuckVerdict::RESTORED_FROM_DISK;
        }
        if (
            $view->phase === StateProtectedModeRuntime::PHASE_ACTIVATING
            && $now - $this->freezeSeenAt > $this->quiesceTimeoutSeconds()
        ) {
            return ProtectedModeStuckVerdict::QUIESCE_OVERDUE;
        }
        if ($now - $this->lastSignOfLife($view) > $this->silenceTimeoutSeconds()) {
            return ProtectedModeStuckVerdict::SILENT;
        }

        return null;
    }

    /**
     * The most recent moment anything was known to be happening behind this freeze.
     *
     * The progress mark when the operation left one, otherwise the moment the freeze settled, and
     * failing that the moment it began - each step back is a weaker claim about the same thing, and
     * the last of them keeps a row that carries no clock at all from reading as silent since 1970.
     *
     * Never earlier than the moment this process began watching, which is the same rule the quiesce
     * round is counted by and it is needed here for the same reason: a leader promoted in the
     * middle of a long healthy operation reads a row whose clocks are hours old - the progress
     * marks were going to the row of the leader before it - and without this floor it would report
     * that freeze stuck in the second it took over, every time. With it, the new leader gives the
     * operation a full window to say it is alive, and reports it if it does not.
     *
     * @param ProtectedModeRuntime $view Freeze row this node carries
     * @return int Epoch seconds of the last sign of life
     */
    private function lastSignOfLife(ProtectedModeRuntime $view): int
    {
        return max($this->freezeSeenAt, $view->progressAt ?? $view->activatedAt ?? $view->startedAt ?? 0);
    }

    /**
     * Starts watching a freeze, or keeps watching the one already adopted.
     *
     * A fingerprint that differs is a different freeze, so the verdict reported for the previous
     * one is dropped with it. The two remembered facts are not cleared here: each is filed against
     * the fingerprint it belongs to, which is what lets one arrive before the tick that adopts the
     * freeze - the initiator can stop, and a restored freeze does stand, before this ever runs.
     *
     * @param string $fingerprint Fingerprint of the freeze on the row
     * @param int $now Epoch seconds this freeze was first seen by this process
     */
    private function adoptFreeze(string $fingerprint, int $now): void
    {
        if ($fingerprint === $this->freeze) {
            return;
        }

        $this->freeze = $fingerprint;
        $this->freezeSeenAt = $now;
        $this->reported = null;
    }

    /**
     * Drops everything remembered: there is no freeze on this node.
     *
     * The one place the two scoped facts are cleared. Keeping them past the lift would let a freeze
     * that happened to fingerprint the same - same operation, same second - inherit a verdict it
     * never earned.
     */
    private function forgetFreeze(): void
    {
        $this->freeze = null;
        $this->freezeSeenAt = 0;
        $this->initiatorLostFor = null;
        $this->initiatorLostAt = null;
        $this->restoredFromDiskFor = null;
        $this->reported = null;
        $this->alarmRaisedAt = null;
        $this->alertedAt = null;
        $this->alarmOperation = null;
        $this->alarmFrozenSince = null;
    }

    /**
     * Mails the operators that the freeze they were told about is over, once and only if it worried
     * anybody.
     *
     * Read off what was kept rather than off the row: the release clears the operation and both
     * clocks before this runs, so a freeze that is over can no longer describe itself.
     *
     * @param int $now Epoch seconds the lift was noticed at
     */
    private function reportLifted(int $now): void
    {
        if ($this->alarmRaisedAt === null) {
            return;
        }

        Logger::logAgentError(
            self::LOG_AGENT_ID,
            sprintf(
                "The freeze for '%s' that was reported stuck has been lifted after %s",
                $this->alarmOperation ?? self::UNNAMED_OPERATION,
                self::elapsedText($now - ($this->alarmFrozenSince ?? $now)),
            ),
        );

        $this->notifier->notifyCleared([
            ProtectedModeClearedMailTemplate::PARAM_NODE_ID => self::nodeName(),
            ProtectedModeClearedMailTemplate::PARAM_OPERATION => $this->alarmOperation ?? self::UNNAMED_OPERATION,
            ProtectedModeClearedMailTemplate::PARAM_LIFTED_AT => self::timestampText($now),
            ProtectedModeClearedMailTemplate::PARAM_HELD_FOR
                => self::elapsedText($now - ($this->alarmFrozenSince ?? $now)),
            ProtectedModeClearedMailTemplate::PARAM_STUCK_FOR => self::elapsedText($now - $this->alarmRaisedAt),
        ]);
    }

    /**
     * Whether the alarm already raised is due to be said again.
     *
     * The alarm is a condition and not an event: the operator who was asleep, or whose mail
     * bounced, is the normal case here, so it repeats while the node stays frozen. Driven by the
     * phase and the clock rather than by whether the previous message landed - a channel that
     * failed is the case that most needs the next attempt.
     *
     * @param int $now Epoch seconds to judge against
     * @return bool Whether a reminder is owed now
     */
    private function reminderIsDue(int $now): bool
    {
        return $this->alertedAt !== null && $now - $this->alertedAt >= $this->alertIntervalSeconds();
    }

    /**
     * The line the operator finds in the log when this node is found stuck.
     *
     * Written first and always, independent of the mail beside it: it is the one report that
     * cannot fail.
     *
     * @param ProtectedModeRuntime $view Freeze row this node carries
     * @param string $problem What is wrong, worded as the alert words it
     * @return string Log line naming the freeze, its phase and the problem
     */
    private static function stuckLogLine(ProtectedModeRuntime $view, string $problem): string
    {
        return sprintf(
            "Freeze for '%s' on phase '%s' is stuck: %s. It will not be lifted automatically - the "
            . 'data here may be half-written, and that decision belongs to a person',
            $view->operation ?? self::UNNAMED_OPERATION,
            $view->phase,
            $problem,
        );
    }

    /**
     * The rendered params the stuck-freeze mail is built from.
     *
     * Rendered here rather than in the template because this is where the clock and the freeze are:
     * a template that computed elapsed time would need both, and the one thing this path must not
     * do is reach for anything while the node is in trouble.
     *
     * @param ProtectedModeRuntime $view Freeze row this node carries
     * @param string $problem What is wrong, in one clause
     * @param int $now Epoch seconds the alert is being sent at
     * @param bool $still Whether this is a reminder rather than the first alert
     * @return array<string, mixed> Params for {@see ProtectedModeStuckMailTemplate}
     */
    private function stuckParams(ProtectedModeRuntime $view, string $problem, int $now, bool $still): array
    {
        $frozenSince = $view->startedAt ?? $this->freezeSeenAt;

        return [
            ProtectedModeStuckMailTemplate::PARAM_NODE_ID => self::nodeName(),
            ProtectedModeStuckMailTemplate::PARAM_OPERATION => $view->operation ?? self::UNNAMED_OPERATION,
            ProtectedModeStuckMailTemplate::PARAM_PHASE => $view->phase,
            ProtectedModeStuckMailTemplate::PARAM_INITIATOR => self::initiatorText($view),
            ProtectedModeStuckMailTemplate::PARAM_PROBLEM => $problem,
            ProtectedModeStuckMailTemplate::PARAM_FROZEN_SINCE => self::timestampText($frozenSince),
            ProtectedModeStuckMailTemplate::PARAM_FROZEN_FOR => self::elapsedText($now - $frozenSince),
            ProtectedModeStuckMailTemplate::PARAM_REPEAT_EVERY => self::elapsedText($this->alertIntervalSeconds()),
            ProtectedModeStuckMailTemplate::PARAM_STILL => $still,
        ];
    }

    /**
     * What is wrong with this freeze, in the one clause the alert and the log line share.
     *
     * One wording for both channels on purpose: an operator who reads the mail and then goes to the
     * log must find the same sentence, not two descriptions of one condition that have to be
     * reconciled at 3am.
     *
     * @param ProtectedModeStuckVerdict $verdict Why the freeze counts as stuck
     * @param ProtectedModeRuntime $view Freeze row this node carries
     * @param int $now Epoch seconds the verdict was reached at
     * @return string Problem clause
     */
    private function problem(ProtectedModeStuckVerdict $verdict, ProtectedModeRuntime $view, int $now): string
    {
        return match ($verdict) {
            ProtectedModeStuckVerdict::INITIATOR_LOST => 'the initiator stopped '
                . self::elapsedText($now - ($this->initiatorLostAt ?? $now))
                . ' ago; nothing is running behind the freeze',
            ProtectedModeStuckVerdict::RESTORED_FROM_DISK
                => 'the daemon restarted while the node was frozen; the freeze was restored from '
                    . 'disk and no operation is running behind it',
            ProtectedModeStuckVerdict::QUIESCE_OVERDUE => self::quiesceProblem(),
            ProtectedModeStuckVerdict::SILENT => 'the initiator has reported no progress for '
                . self::elapsedText($now - $this->lastSignOfLife($view)),
        };
    }

    /**
     * Names the nodes that never confirmed the freeze, when this node can name them.
     *
     * The list is the leader's own bookkeeping and not part of the row
     * ({@see ProtectedModeQuiesceRoster}), so a process that cannot reach it says the same thing
     * without the names - which is worth less to the operator but is never wrong.
     *
     * @return string Problem clause for a round that never closed
     */
    private static function quiesceProblem(): string
    {
        $switch = Hilos::$cluster?->protectedMode();
        $pending = $switch instanceof ProtectedModeQuiesceRoster ? $switch->pendingNodeIds() : [];

        return $pending === []
            ? 'at least one node never confirmed the freeze'
            : 'these nodes never confirmed the freeze: ' . implode(', ', $pending);
    }

    /**
     * How this node is called in a message to a person.
     *
     * The cluster node id when there is one, and the machine's hostname otherwise: an operator
     * reading an alert at 3am has to know which box to open, and a single-node installation has no
     * node id to give them. Both are in-memory reads.
     *
     * @return string Node id, hostname, or a placeholder when neither can be had
     */
    private static function nodeName(): string
    {
        try {
            if (Hilos::$cluster?->isEnabled() === true) {
                return Hilos::$cluster->identity()->nodeId;
            }
        } catch (Throwable $e) {
            Logger::logAgentError(self::LOG_AGENT_ID, 'Node identity is unreadable: ' . $e->getMessage());
        }

        $hostname = gethostname();

        return $hostname === false ? self::UNNAMED_NODE : $hostname;
    }

    /**
     * Who froze the node, as the alert names them.
     *
     * @param ProtectedModeRuntime $view Freeze row this node carries
     * @return string Initiator sentence for the alert
     */
    private static function initiatorText(ProtectedModeRuntime $view): string
    {
        return sprintf(
            '%s agent on %s',
            self::initiatorAgentId($view) ?? self::UNNAMED_INITIATOR,
            $view->initiatorNodeId ?? self::nodeName(),
        );
    }

    /**
     * A duration as a person reads it.
     *
     * Two shapes, both from the approved copy: minutes on their own up to an hour, and hours and
     * minutes past it. Seconds are never shown - every threshold here is minutes, and a number that
     * precise would suggest the alert is about timing rather than about a node nobody can reach.
     *
     * @param int $seconds Duration in seconds
     * @return string Duration rendered for a human
     */
    private static function elapsedText(int $seconds): string
    {
        if ($seconds < self::SECONDS_PER_MINUTE) {
            return 'less than a minute';
        }

        $minutes = intdiv($seconds, self::SECONDS_PER_MINUTE);
        if ($minutes < self::MINUTES_PER_HOUR) {
            return $minutes === 1 ? '1 minute' : $minutes . ' minutes';
        }

        return sprintf(
            '%dh %02dm',
            intdiv($minutes, self::MINUTES_PER_HOUR),
            $minutes % self::MINUTES_PER_HOUR,
        );
    }

    /**
     * A moment as a person reads it, in UTC.
     *
     * UTC and not the node's local zone: the alert may be read from anywhere, and two nodes writing
     * their own local time into one operator's mailbox is how an incident timeline gets misread.
     *
     * @param int $timestamp Epoch seconds
     * @return string Timestamp rendered for a human
     */
    private static function timestampText(int $timestamp): string
    {
        return gmdate('j M Y H:i:s', $timestamp) . ' UTC';
    }

    /**
     * How long the alert waits before saying the same thing again.
     *
     * @return int Seconds between reminders
     */
    private function alertIntervalSeconds(): int
    {
        $this->alertInterval ??= $this->readTimeout(
            EnvConstants::HILOS_PROTECTED_MODE_ALERT_INTERVAL,
            self::ALERT_INTERVAL_FALLBACK_SECONDS,
        );

        return $this->alertInterval;
    }

    /**
     * The freeze row this node carries, or null when this process holds no runtime state.
     *
     * Null is never a project opting out - the framework mounts this row for every project that has
     * an RT context - so what it means here is a process that cannot see a freeze and therefore has
     * nothing to watch.
     *
     * @return ?ProtectedModeRuntime Freeze row, or null when runtime state is unavailable
     */
    private function freezeRow(): ?ProtectedModeRuntime
    {
        return Hilos::$rt?->hilosProtectedModeRuntime;
    }

    /**
     * How long a quiesce round may stay open before the leader is told it never closed.
     *
     * Read once and kept, unlike {@see DaemonManager::reHydrateTimeoutSeconds()} which re-reads per
     * use: this runs on every iteration of the daemon loop, and a value that cannot be parsed would
     * otherwise log its complaint at loop frequency and drown the alert it exists to serve. The
     * environment of a running daemon does not change under it, so the read is worth doing once.
     *
     * @return int Seconds a quiesce round may stay open
     */
    private function quiesceTimeoutSeconds(): int
    {
        $this->quiesceTimeout ??= $this->readTimeout(
            EnvConstants::HILOS_PROTECTED_MODE_QUIESCE_TIMEOUT,
            self::QUIESCE_TIMEOUT_FALLBACK_SECONDS,
        );

        return $this->quiesceTimeout;
    }

    /**
     * How long a freeze may go without a sign of life before it is reported.
     *
     * @return int Seconds a freeze may stay silent
     */
    private function silenceTimeoutSeconds(): int
    {
        $this->silenceTimeout ??= $this->readTimeout(
            EnvConstants::HILOS_PROTECTED_MODE_SILENCE_TIMEOUT,
            self::SILENCE_TIMEOUT_FALLBACK_SECONDS,
        );

        return $this->silenceTimeout;
    }

    /**
     * Reads one threshold, degrading to its fallback rather than taking the master down.
     *
     * An exception escaping here would end the daemon's run loop, which on a frozen node means
     * killing the one process able to report the freeze at all - the failure this class exists to
     * survive, arriving through the class itself.
     *
     * @param EnvConstants $name Environment value holding the threshold
     * @param int $fallbackSeconds Value to use when the environment cannot answer
     * @return int Threshold in seconds
     */
    private function readTimeout(EnvConstants $name, int $fallbackSeconds): int
    {
        try {
            return Hilos::$env->int($name);
        } catch (EnvException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Threshold '{$name->name}' is unreadable, falling back to {$fallbackSeconds}s: {$e->getMessage()}",
            );

            return $fallbackSeconds;
        }
    }

    /**
     * What tells one freeze from the next.
     *
     * The operation and the moment it began: a freeze is replaced only by entering a new one, and
     * entering rewrites both. Deliberately not the phase - a freeze moving from activating to
     * active is the same freeze, and a fingerprint that changed under it would restart every clock
     * the watchdog keeps.
     *
     * @param ProtectedModeRuntime $view Freeze row this node carries
     * @return string Fingerprint of the freeze on the row
     */
    private static function fingerprintOf(ProtectedModeRuntime $view): string
    {
        return ($view->operation ?? self::UNNAMED_OPERATION) . '@' . ($view->startedAt ?? 0);
    }

    /**
     * The agent id the row names as the initiator, in the form a stop report carries.
     *
     * Joined the way {@see AbstractAgent::getId()} joins it, because that is the shape the stop
     * arrives in and the row keeps the two halves apart.
     *
     * @param ProtectedModeRuntime $view Freeze row this node carries
     * @return ?string Initiator agent id, or null when the row names no initiator
     */
    private static function initiatorAgentId(ProtectedModeRuntime $view): ?string
    {
        $type = $view->initiatorAgentType;
        if ($type === null) {
            return null;
        }

        $index = $view->initiatorAgentIndex;

        return $index === null ? $type : $type . AgentConstants::ID_SEPARATOR . $index;
    }
}
