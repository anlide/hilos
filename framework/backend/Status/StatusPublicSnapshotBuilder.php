<?php

declare(strict_types=1);

namespace Hilos\Status;

use AppendIterator;
use ArrayIterator;
use Iterator;
use IteratorIterator;
use Hilos\Constants\TimeConstants;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Database\View\Collection\StatusIncidentUpdates;
use Hilos\Database\View\Collection\StatusWorks;
use Hilos\Database\View\Collection\StatusMaintenancePeriods;
use Hilos\Database\View\Collection\StatusSilencePeriods;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Status\Exception\StatusException;
use SplMinHeap;
use SplPriorityQueue;
use Throwable;

/**
 * Private, portioned build driven by the Status publisher lifecycle.
 * The owner must reset on every source mutation, freeze entry and database replacement.
 * No DB View/Object or StatusSnapshot survives a call; another process reads only published files.
 */
final class StatusPublicSnapshotBuilder
{
    public const int HISTORY_WINDOW_DAYS = 90;

    private ?StatusPublicSnapshot $base = null;
    private ?StatusPublicSnapshot $snapshot = null;
    private ?int $afterIncidentId = null;
    private ?int $afterUpdateId = null;
    private int $sinceMs = 0;
    private int $silenceSinceMs = 0;
    private bool $historyComplete = false;
    private bool $outageOrderingComplete = false;
    private bool $worksComplete = false;
    private bool $periodsComplete = false;
    private ?int $afterWorkId = null;
    private ?int $afterPeriodId = null;
    /** @var ?SplPriorityQueue<array{int, int}, StatusPublicWork> Sanitized announcements in ascending start/id order */
    private ?SplPriorityQueue $orderedWorks = null;
    /** @var array<string, true> Public identities already queued; private to this generation */
    private array $workIds = [];
    /** @var array<string, true> Known announcements retained by an active observed period */
    private array $activeWorkIds = [];
    /** @var list<StatusPublicWork> Ordered sanitized announcements */
    private array $works = [];
    /** @var list<StatusPublicMaintenancePeriod> Sanitized observed periods */
    private array $periods = [];
    /** @var ?ArrayIterator<int, StatusPublicMaintenancePeriod> Detached observation cursor */
    private ?ArrayIterator $detachedPeriods = null;
    /** @var ?ArrayIterator<int, StatusPublicWork> Detached announcement cursor */
    private ?ArrayIterator $detachedWorks = null;
    /** @var array<int, StatusPublicOutage> Sanitized incident values, keyed only for bounded timeline reads */
    private array $pending = [];
    /** @var list<StatusPublicUpdate> Current incident's sanitized timeline, already in canonical order */
    private array $updates = [];
    /** @var ?SplPriorityQueue<array{int, int}, StatusPublicOutage> Completed values ordered by detection and internal tie-break */
    private ?SplPriorityQueue $ordered = null;
    /** @var list<StatusPublicOutage> History drained in bounded portions */
    private array $outages = [];
    /** @var ?ArrayIterator<int, StatusPublicOutage> Detached archive cursor, safe in every freeze phase */
    private ?ArrayIterator $detached = null;
    private ?int $nextHistoryExpiryAt = null;
    private bool $silencesComplete = false;
    private ?int $afterSilenceId = null;
    /** @var ?Iterator<int|string, StatusPublicSilencePeriod> Detached unimported or frozen archive */
    private ?Iterator $detachedSilences = null;
    /** @var ?SplMinHeap<array{int, string, StatusPublicSilencePeriod}> Stable start/id ordering */
    private ?SplMinHeap $orderedSilences = null;
    /** @var array<string, StatusPublicSilencePeriod> Deduplication of DB and still-present committed transit */
    private array $silenceIds = [];
    /** @var list<StatusPublicSilencePeriod> Bounded ordering output */
    private array $silences = [];

    /**
     * Starts a new generation and discards every value and cursor of its predecessor.
     * This copies current values only; it does not load history or timelines.
     *
     * @param StatusSnapshot $current Available incident source and permitted current health
     * @param string $revision Opaque revision for this generation
     * @param int $generatedAt Generation epoch milliseconds UTC and history-window reference
     * @param int $pollIntervalSeconds Positive reader polling interval
     * @param int $staleAfterSeconds Positive heartbeat freshness threshold
     * @param iterable<StatusPublicSilencePeriod> $transit Closed observations not yet removed after import
     * @throws StatusException When the source is unavailable or publication metadata is invalid
     */
    public function begin(
        StatusSnapshot $current,
        string $revision,
        int $generatedAt,
        int $pollIntervalSeconds,
        int $staleAfterSeconds,
        iterable $transit = [],
    ): void {
        $this->reset();
        if ($current->incidents === null) {
            throw new StatusException('Public history requires an available incident source.');
        }
        $this->base = (new StatusPublicSnapshot($revision, $generatedAt, $pollIntervalSeconds, $staleAfterSeconds, [], [], []))
            ->withCurrent($current, $revision, $generatedAt);
        $this->silenceSinceMs = $generatedAt - self::HISTORY_WINDOW_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        $this->sinceMs = max(0, $this->silenceSinceMs);
        $this->ordered = new SplPriorityQueue();
        $this->ordered->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $this->orderedWorks = new SplPriorityQueue();
        $this->orderedWorks->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $this->orderedSilences = new SplMinHeap();
        $this->detachedSilences = is_array($transit) ? new ArrayIterator($transit) : new IteratorIterator($transit);
    }

    /**
     * At most one history portion OR one timeline portion (anchor plus rows) is read per call.
     * Final ordering also drains only one portion; incomplete generations have no readable result.
     * Unavailable/frozen sources and failures discard the generation before returning or throwing.
     *
     * @throws HilosException When a bounded source read fails
     */
    public function advance(): void
    {
        if ($this->base === null || $this->snapshot !== null) {
            return;
        }
        if ($this->detached !== null || $this->detachedPeriods !== null || $this->detachedWorks !== null) {
            try {
                $this->advanceDetached();
            } catch (Throwable $failure) {
                $this->reset();
                throw $failure instanceof HilosException ? $failure : new StatusException('Detached status build failed.', previous: $failure);
            }
            return;
        }
        if (Hilos::$db === null || Hilos::$rt?->hilosProtectedModeRuntime === null
            || Hilos::$rt->hilosProtectedModeRuntime->silencesUnstoppedWriters()) {
            $this->reset();
            return;
        }
        try {
            $this->advancePortion();
        } catch (Throwable $failure) {
            $this->reset();
            throw $failure instanceof HilosException ? $failure : new StatusException('Public status build failed.', previous: $failure);
        }
    }

    /** @return bool Whether a complete generation can be obtained without source reads */
    public function isReady(): bool
    {
        return $this->snapshot !== null;
    }

    /** @return bool Whether a generation still requires bounded advancement */
    public function isBuilding(): bool
    {
        return $this->base !== null;
    }

    /**
     * @return StatusPublicSnapshot Complete non-consuming public copy
     * @throws StatusException When no complete generation is ready
     */
    public function getSnapshot(): StatusPublicSnapshot
    {
        return $this->snapshot ?? throw new StatusException('The public status generation is incomplete.');
    }

    /**
     * Reuses only sanitized archive values while DB reads are forbidden or unavailable.
     * Expiry filtering remains bounded and uses the new generation's reference moment.
     *
     * @param StatusPublicSnapshot $snapshot Current public overlay and last complete archive
     * @param iterable<StatusPublicSilencePeriod> $transit Closed durable facts not yet imported
     */
    public function beginDetached(StatusPublicSnapshot $snapshot, iterable $transit = []): void
    {
        $this->reset();
        $this->base = $snapshot;
        $this->silenceSinceMs = $snapshot->generatedAt - self::HISTORY_WINDOW_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        $this->sinceMs = max(0, $this->silenceSinceMs);
        $this->detached = new ArrayIterator($snapshot->outages);
        $this->detachedPeriods = new ArrayIterator($snapshot->maintenancePeriods);
        $this->detachedWorks = new ArrayIterator($snapshot->scheduledWorks);
        $silences = new AppendIterator();
        $silences->append(new ArrayIterator($snapshot->silencePeriods));
        $silences->append(is_array($transit) ? new ArrayIterator($transit) : new IteratorIterator($transit));
        $this->detachedSilences = $silences;
        $this->orderedSilences = new SplMinHeap();
    }

    /** @return ?int First moment an announcement or completed fact leaves the archive, or null */
    public function getNextHistoryExpiryAt(): ?int
    {
        return $this->nextHistoryExpiryAt;
    }

    /** Discards ready and partial values; the next generation must begin from a new source snapshot. */
    public function reset(): void
    {
        $this->base = null;
        $this->snapshot = null;
        $this->afterIncidentId = null;
        $this->afterUpdateId = null;
        $this->sinceMs = 0;
        $this->silenceSinceMs = 0;
        $this->historyComplete = false;
        $this->outageOrderingComplete = false;
        $this->worksComplete = false;
        $this->periodsComplete = false;
        $this->afterWorkId = null;
        $this->afterPeriodId = null;
        $this->orderedWorks = null;
        $this->workIds = [];
        $this->activeWorkIds = [];
        $this->works = [];
        $this->periods = [];
        $this->detachedPeriods = null;
        $this->detachedWorks = null;
        $this->pending = [];
        $this->updates = [];
        $this->ordered = null;
        $this->outages = [];
        $this->detached = null;
        $this->nextHistoryExpiryAt = null;
        $this->silencesComplete = false;
        $this->afterSilenceId = null;
        $this->detachedSilences = null;
        $this->orderedSilences = null;
        $this->silenceIds = [];
        $this->silences = [];
    }

    /** Advances exactly one finite read or ordering portion of the active generation. */
    private function advancePortion(): void
    {
        if ($this->pending !== []) {
            $incidentId = array_key_first($this->pending);
            $portion = Hilos::$db->statusIncidentUpdates->timelinePortion(
                $incidentId, $this->afterUpdateId, StatusIncidentUpdates::MAX_PORTION_ROWS,
            );
            foreach ($portion as $update) {
                $this->afterUpdateId = $update->id;
                $publicUpdate = StatusPublicUpdate::fromUpdate($update);
                if ($publicUpdate !== null) {
                    $this->updates[] = $publicUpdate;
                }
            }
            if (count($portion) < StatusIncidentUpdates::MAX_PORTION_ROWS) {
                $outage = $this->pending[$incidentId]->withUpdates($this->updates);
                $this->ordered->insert($outage, [$outage->detectedAt, $incidentId]);
                unset($this->pending[$incidentId]);
                $this->updates = [];
                $this->afterUpdateId = null;
            }
            return;
        }
        if (!$this->historyComplete) {
            $portion = Hilos::$db->statusIncidents->publishedIntersecting(
                $this->sinceMs, $this->afterIncidentId, StatusIncidents::MAX_PORTION_ROWS,
            );
            foreach ($portion as $incident) {
                $this->afterIncidentId = $incident->id;
                $this->pending[$incident->id] = StatusPublicOutage::fromIncident($incident);
                $this->rememberHistoryExpiry($this->pending[$incident->id]);
            }
            $this->historyComplete = count($portion) < StatusIncidents::MAX_PORTION_ROWS;
            return;
        }
        if (!$this->outageOrderingComplete) {
            for ($remaining = StatusIncidents::MAX_PORTION_ROWS; $remaining > 0 && !$this->ordered->isEmpty(); --$remaining) {
                $this->outages[] = $this->ordered->extract();
            }
            $this->outageOrderingComplete = $this->ordered->isEmpty();
            return;
        }
        // Each work also reads its component bridge. One row keeps those reads bounded per pass.
        if (!$this->worksComplete) {
            $portion = Hilos::$db->statusWorks->upcomingPortion($this->base->generatedAt, $this->afterWorkId, 1);
            foreach ($portion as $work) {
                $this->afterWorkId = $work->id;
                $this->queueWork(StatusPublicWork::fromWork($work), $work->id);
            }
            $this->worksComplete = count($portion) === 0;
            return;
        }
        if (!$this->periodsComplete) {
            $portion = Hilos::$db->statusMaintenancePeriods->intersecting($this->sinceMs, $this->afterPeriodId, 1);
            foreach ($portion as $period) {
                $this->afterPeriodId = $period->id;
                $public = StatusPublicMaintenancePeriod::fromPeriod($period);
                $this->periods[] = $public;
                $this->rememberPeriod($public);
                if ($public->completionObservedAt === null && $public->workId !== null && !isset($this->workIds[$public->workId])) {
                    $work = $period->work;
                    if ($work !== null && $work->canceledAt === null) {
                        $this->queueWork(StatusPublicWork::fromWork($work), $work->id);
                    }
                }
            }
            $this->periodsComplete = count($portion) === 0;
            return;
        }
        if (!$this->silencesComplete) {
            $portion = Hilos::$db->statusSilencePeriods->intersecting(
                $this->silenceSinceMs, $this->afterSilenceId, StatusSilencePeriods::MAX_PORTION_ROWS,
            );
            foreach ($portion as $period) {
                $this->afterSilenceId = $period->id;
                $this->queueSilence(new StatusPublicSilencePeriod($period->publicId, $period->startedAt, $period->endedAt));
            }
            $this->silencesComplete = count($portion) < StatusSilencePeriods::MAX_PORTION_ROWS;
            return;
        }
        if ($this->detachedSilences !== null) {
            for ($step = 0; $step < StatusSilencePeriods::MAX_PORTION_ROWS && $this->detachedSilences->valid(); ++$step) {
                $this->queueSilence($this->detachedSilences->current());
                $this->detachedSilences->next();
            }
            if (!$this->detachedSilences->valid()) {
                $this->detachedSilences = null;
            }
            return;
        }
        if (!$this->orderedSilences->isEmpty()) {
            $this->drainSilences();
            return;
        }
        for ($remaining = StatusWorks::MAX_PORTION_ROWS; $remaining > 0 && !$this->orderedWorks->isEmpty(); --$remaining) {
            $work = $this->orderedWorks->extract();
            $this->works[] = $work;
            $this->rememberWorkExpiry($work);
        }
        if ($this->orderedWorks->isEmpty()) {
            $this->finishGeneration();
        }
    }

    /** Advances one sanitized archive portion without consulting any facade. */
    private function advanceDetached(): void
    {
        for ($step = 0; $step < StatusIncidents::MAX_PORTION_ROWS; ++$step) {
            if ($this->detached?->valid()) {
                $outage = $this->detached->current();
                $this->detached->next();
                if ($outage->resolvedAt === null || $outage->resolvedAt >= $this->sinceMs) {
                    $this->outages[] = $outage;
                    $this->rememberHistoryExpiry($outage);
                }
                continue;
            }
            if ($this->detachedPeriods?->valid()) {
                $period = $this->detachedPeriods->current();
                $this->detachedPeriods->next();
                if ($period->completionObservedAt === null || ($period->endedAt ?? $period->completionObservedAt) >= $this->sinceMs) {
                    $this->periods[] = $period;
                    $this->rememberPeriod($period);
                }
                continue;
            }
            if ($this->detachedWorks?->valid()) {
                $work = $this->detachedWorks->current();
                $this->detachedWorks->next();
                if ($work->endsAt > $this->base->generatedAt || isset($this->activeWorkIds[$work->id])) {
                    $this->works[] = $work;
                    $this->rememberWorkExpiry($work);
                }
                continue;
            }
            if ($this->detachedSilences?->valid()) {
                $this->queueSilence($this->detachedSilences->current());
                $this->detachedSilences->next();
                continue;
            }
            break;
        }
        if (!$this->detached?->valid() && !$this->detachedPeriods?->valid() && !$this->detachedWorks?->valid()
            && !$this->detachedSilences?->valid()) {
            $this->drainSilences();
            if ($this->orderedSilences->isEmpty()) {
                $this->finishGeneration();
            }
        }
    }

    /**
     * @param StatusPublicSilencePeriod $period Detached DB or private-transit closed fact
     * @throws StatusException When the same public identity carries different boundaries
     */
    private function queueSilence(StatusPublicSilencePeriod $period): void
    {
        if ($period->endedAt <= $this->silenceSinceMs) {
            return;
        }
        $existing = $this->silenceIds[$period->id] ?? null;
        if ($existing !== null) {
            if ($existing != $period) {
                throw new StatusException('Silence transit disagrees with the persisted archive.');
            }
            return;
        }
        $this->silenceIds[$period->id] = $period;
        $this->orderedSilences->insert([$period->startedAt, 'id:' . $period->id, $period]);
        $this->rememberCompletionExpiry($period->endedAt - 1);
    }

    /** Drains at most one ordered closed-history portion. */
    private function drainSilences(): void
    {
        for ($step = 0; $step < StatusSilencePeriods::MAX_PORTION_ROWS && !$this->orderedSilences->isEmpty(); ++$step) {
            $this->silences[] = $this->orderedSilences->extract()[2];
        }
    }

    /**
     * @param StatusPublicWork $work Detached announcement
     * @param int $id Private source tie-break, never serialized
     */
    private function queueWork(StatusPublicWork $work, int $id): void
    {
        if (!isset($this->workIds[$work->id])) {
            $this->workIds[$work->id] = true;
            $this->orderedWorks->insert($work, [-$work->startsAt, -$id]);
        }
    }

    /** @param StatusPublicMaintenancePeriod $period Retained detached observation */
    private function rememberPeriod(StatusPublicMaintenancePeriod $period): void
    {
        if ($period->completionObservedAt === null) {
            if ($period->workId !== null) {
                $this->activeWorkIds[$period->workId] = true;
            }
        } else {
            $this->rememberCompletionExpiry($period->endedAt ?? $period->completionObservedAt);
        }
    }

    /** @param StatusPublicWork $work Retained detached announcement */
    private function rememberWorkExpiry(StatusPublicWork $work): void
    {
        if (!isset($this->activeWorkIds[$work->id])) {
            $this->nextHistoryExpiryAt = $this->nextHistoryExpiryAt === null
                ? $work->endsAt : min($this->nextHistoryExpiryAt, $work->endsAt);
        }
    }

    /** Installs only the complete generation and releases its private cursors. */
    private function finishGeneration(): void
    {
        $snapshot = $this->base->withOutages($this->outages)->withMaintenance($this->works, $this->periods)->withSilencePeriods($this->silences);
        $expiry = $this->nextHistoryExpiryAt;
        $this->reset();
        $this->snapshot = $snapshot;
        $this->nextHistoryExpiryAt = $expiry;
    }

    /** @param StatusPublicOutage $outage Retained public incident, never a DB reference */
    private function rememberHistoryExpiry(StatusPublicOutage $outage): void
    {
        if ($outage->resolvedAt !== null) {
            $this->rememberCompletionExpiry($outage->resolvedAt);
        }
    }

    /** @param int $completedAt Retention boundary, not necessarily an exact interval end */
    private function rememberCompletionExpiry(int $completedAt): void
    {
        $windowMs = self::HISTORY_WINDOW_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        if ($completedAt < PHP_INT_MAX - $windowMs) {
            $expiry = $completedAt + $windowMs + 1;
            if ($this->nextHistoryExpiryAt === null || $expiry < $this->nextHistoryExpiryAt) {
                $this->nextHistoryExpiryAt = $expiry;
            }
        }
    }
}
