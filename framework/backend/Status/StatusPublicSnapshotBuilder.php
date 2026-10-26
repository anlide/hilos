<?php

declare(strict_types=1);

namespace Hilos\Status;

use ArrayIterator;
use Hilos\Constants\TimeConstants;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Database\View\Collection\StatusIncidentUpdates;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Status\Exception\StatusException;
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
    private bool $historyComplete = false;
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

    /**
     * Starts a new generation and discards every value and cursor of its predecessor.
     * This copies current values only; it does not load history or timelines.
     *
     * @param StatusSnapshot $current Available incident source and permitted current health
     * @param string $revision Opaque revision for this generation
     * @param int $generatedAt Generation epoch milliseconds UTC and history-window reference
     * @param int $pollIntervalSeconds Positive reader polling interval
     * @param int $staleAfterSeconds Positive heartbeat freshness threshold
     * @throws StatusException When the source is unavailable or publication metadata is invalid
     */
    public function begin(
        StatusSnapshot $current,
        string $revision,
        int $generatedAt,
        int $pollIntervalSeconds,
        int $staleAfterSeconds,
    ): void {
        $this->reset();
        if ($current->incidents === null) {
            throw new StatusException('Public history requires an available incident source.');
        }
        $this->base = (new StatusPublicSnapshot($revision, $generatedAt, $pollIntervalSeconds, $staleAfterSeconds, [], [], []))
            ->withCurrent($current, $revision, $generatedAt);
        $this->sinceMs = max(0, $generatedAt - self::HISTORY_WINDOW_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND);
        $this->ordered = new SplPriorityQueue();
        $this->ordered->setExtractFlags(SplPriorityQueue::EXTR_DATA);
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
        if ($this->detached !== null) {
            for ($work = 0; $work < StatusIncidents::MAX_PORTION_ROWS && $this->detached->valid(); ++$work) {
                $outage = $this->detached->current();
                $this->detached->next();
                if ($outage->resolvedAt === null || $outage->resolvedAt >= $this->sinceMs) {
                    $this->outages[] = $outage;
                    $this->rememberHistoryExpiry($outage);
                }
            }
            if (!$this->detached->valid()) {
                $this->snapshot = $this->base->withOutages($this->outages);
                $this->base = null;
                $this->detached = null;
                $this->outages = [];
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
     */
    public function beginDetached(StatusPublicSnapshot $snapshot): void
    {
        $this->reset();
        $this->base = $snapshot;
        $this->sinceMs = max(0, $snapshot->generatedAt - self::HISTORY_WINDOW_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND);
        $this->detached = new ArrayIterator($snapshot->outages);
    }

    /** @return ?int First moment a retained resolved outage leaves the archive, or null */
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
        $this->historyComplete = false;
        $this->pending = [];
        $this->updates = [];
        $this->ordered = null;
        $this->outages = [];
        $this->detached = null;
        $this->nextHistoryExpiryAt = null;
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
        for ($remaining = StatusIncidents::MAX_PORTION_ROWS; $remaining > 0 && !$this->ordered->isEmpty(); --$remaining) {
            $this->outages[] = $this->ordered->extract();
        }
        if ($this->ordered->isEmpty()) {
            $this->snapshot = $this->base->withOutages($this->outages);
            $this->base = null;
            $this->ordered = null;
            $this->outages = [];
        }
    }

    /** @param StatusPublicOutage $outage Retained public incident, never a DB reference */
    private function rememberHistoryExpiry(StatusPublicOutage $outage): void
    {
        $windowMs = self::HISTORY_WINDOW_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        if ($outage->resolvedAt !== null && $outage->resolvedAt < PHP_INT_MAX - $windowMs) {
            $expiry = $outage->resolvedAt + $windowMs + 1;
            if ($this->nextHistoryExpiryAt === null || $expiry < $this->nextHistoryExpiryAt) {
                $this->nextHistoryExpiryAt = $expiry;
            }
        }
    }
}
