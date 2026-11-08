<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Closure;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;

/**
 * The batch a process gathers for the journal agent of its node (HIL-1154).
 *
 * Records wait in memory and leave as one frame ({@see HilosSignalConstants::ANALYTICS_JOURNAL_APPEND})
 * once a second, at 64 KiB gathered and at the end of the process. A frame queued before the
 * process has its connection to the master is not lost: the queue is only taken when the
 * connection is there.
 *
 * A batch stands on its own: it opens with the description of every session its records name -
 * the worker first, then the agents - so a batch lost on the way loses only its own events, and
 * every later event of the same agent still finds its session described in its own batch.
 * The frame carries its event count and source losses too: oversized payloads can be removed
 * without losing the event, whole records can be dropped, and a freeze can discard a batch.
 */
final class AnalyticsJournalOutbox
{
    public const int FLUSH_INTERVAL_MS = 1000;
    public const int FLUSH_BYTES = 65536;

    /** @var array<string, string> Session key to the line describing it, in the order first named */
    private array $descriptions = [];

    /** @var list<string> Record lines, in the order they happened */
    private array $records = [];

    /** @var array<string, AnalyticsLossCount> Loss counts gathered by reason for the next frame */
    private array $losses = [];

    /** @var int Event records in the next frame, excluding session descriptions */
    private int $events = 0;

    /** @var int Bytes of the descriptions and records gathered */
    private int $bytes = 0;

    /** @var ?Closure(): void Observer of pending data and the single interval basis */
    private ?Closure $scheduleWake = null;

    /**
     * @param int $lastFlushAtMs Monotonic milliseconds the process interval is counted from
     */
    public function __construct(private int $lastFlushAtMs)
    {
    }

    /** @param Closure(): void $wake Exact process-local scheduling observer */
    public function setScheduleWake(Closure $wake): void
    {
        $this->scheduleWake = $wake;
    }

    /** @param Closure(): void $wake Original observer, never a replacement binding */
    public function removeScheduleWake(Closure $wake): void
    {
        if ($this->scheduleWake === $wake) {
            $this->scheduleWake = null;
        }
    }

    /** @return ?int Original monotonic-millisecond deadline, or null for an empty batch */
    public function nextFlushAt(): ?int
    {
        return $this->hasPendingData() ? $this->lastFlushAtMs + self::FLUSH_INTERVAL_MS : null;
    }

    /**
     * Names sessions for the next batch without a record: a session just opened has a row even if nothing happens in it.
     *
     * @param array<string, array<string, mixed>> $sessions Session key to its description record, the worker first
     * @param int $nowMs Monotonic milliseconds, used only for a full-batch flush
     * @throws InvalidArgumentException When a batch that grew full cannot be named
     */
    public function describe(array $sessions, int $nowMs): void
    {
        foreach ($sessions as $key => $description) {
            if (isset($this->descriptions[$key])) {
                continue;
            }

            $line = AnalyticsJournalRecord::encode($description);
            if ($line === null) {
                continue;
            }

            $this->descriptions[$key] = $line;
            $this->bytes += strlen($line);
        }

        $this->flushWhenFull($nowMs);
        ($this->scheduleWake)?->__invoke();
    }

    /**
     * Adds a record, with the sessions it names.
     *
     * @param array<string, mixed> $record Record built by {@see AnalyticsJournalRecord}
     * @param array<string, array<string, mixed>> $sessions Session key to the description of every session the record names, the worker first
     * @param int $recordAtMs Epoch milliseconds for record/payload loss accounting
     * @param int $intervalNowMs Monotonic milliseconds for a full-batch flush
     * @throws InvalidArgumentException When a batch that grew full cannot be named
     */
    public function add(array $record, array $sessions, int $recordAtMs, int $intervalNowMs): void
    {
        $encoded = AnalyticsJournalRecord::encodeEvent($record);
        if ($encoded->line === null) {
            $this->countLoss(AnalyticsLossReason::RECORD_DROPPED, 1, $recordAtMs, $recordAtMs);
            return;
        }

        if ($encoded->payloadDropped) {
            $this->countLoss(AnalyticsLossReason::PAYLOAD_DROPPED, 1, $recordAtMs, $recordAtMs);
        }

        $this->records[] = $encoded->line;
        $this->events++;
        $this->bytes += strlen($encoded->line);
        $this->describe($sessions, $intervalNowMs);
    }

    /**
     * Adds a loss to the next frame, retaining its first and last moment.
     *
     * @param AnalyticsLossReason $reason Why the events were lost
     * @param int $events Number of events lost; nonpositive counts change nothing
     * @param int $fromMs First loss moment in epoch milliseconds
     * @param int $toMs Last loss moment in epoch milliseconds
     */
    public function countLoss(AnalyticsLossReason $reason, int $events, int $fromMs, int $toMs): void
    {
        if ($events <= 0) {
            return;
        }

        $previous = $this->losses[$reason->value] ?? null;
        $this->losses[$reason->value] = new AnalyticsLossCount(
            $reason,
            ($previous?->events ?? 0) + $events,
            $previous === null ? $fromMs : min($previous->fromTs, $fromMs),
            $previous === null ? $toMs : max($previous->toTs, $toMs),
        );
        ($this->scheduleWake)?->__invoke();
    }

    /** @return bool Whether descriptions, records or loss counts still need a flush */
    public function hasPendingData(): bool
    {
        return $this->descriptions !== [] || $this->records !== [] || $this->losses !== [];
    }

    /**
     * @param int $nowMs Monotonic milliseconds of the check
     * @return bool True when the interval since the last flush has elapsed
     */
    public function isFlushDue(int $nowMs): bool
    {
        return $nowMs - $this->lastFlushAtMs >= self::FLUSH_INTERVAL_MS;
    }

    /**
     * Sends the batch when a second has passed since the last one.
     *
     * @param int $nowMs Monotonic milliseconds of the tick
     * @throws InvalidArgumentException When the batch cannot be named
     */
    public function flushIfDue(int $nowMs): void
    {
        if ($this->isFlushDue($nowMs)) {
            $this->flush($nowMs);
        }
    }

    /**
     * Sends what was gathered as one frame to the journal agent of this node; nothing when nothing was.
     *
     * @param int $nowMs Monotonic milliseconds of the actual flush, including an empty flush
     * @throws InvalidArgumentException When the batch cannot be named
     */
    public function flush(int $nowMs): void
    {
        $this->lastFlushAtMs = $nowMs;
        if ($this->descriptions === [] && $this->records === [] && $this->losses === []) {
            ($this->scheduleWake)?->__invoke();
            return;
        }

        $lines = [...array_values($this->descriptions), ...$this->records];
        $events = $this->events;
        $losses = array_values($this->losses);
        $this->clear();
        $this->losses = [];
        ($this->scheduleWake)?->__invoke();

        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::ANALYTICS_JOURNAL_APPEND),
            signalData: new AgentSignalData(data: new AnalyticsJournalAppendSignalData($lines, $events, $losses)),
        );
    }

    /**
     * Throws gathered lines away and returns their event count. Loss counts survive until flush.
     *
     * @return int Events whose lines were thrown away
     */
    public function clear(): int
    {
        $events = $this->events;
        $this->descriptions = [];
        $this->records = [];
        $this->bytes = 0;
        $this->events = 0;
        ($this->scheduleWake)?->__invoke();

        return $events;
    }

    /**
     * @param int $nowMs Monotonic milliseconds of the call
     * @throws InvalidArgumentException When the batch cannot be named
     */
    private function flushWhenFull(int $nowMs): void
    {
        if ($this->bytes >= self::FLUSH_BYTES) {
            $this->flush($nowMs);
        }
    }
}
