<?php

declare(strict_types=1);

namespace Hilos\Status;

use ArrayIterator;
use Generator;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Status\Exception\StatusException;
use Hilos\Utils\Helpers\RandomHelper;
use JsonException;
use Random\RandomException;

/**
 * The Status owner's private publication cursor, not a DB/RT cache. Only detached public
 * values survive here; other processes read the published files, never this instance.
 */
final class StatusPublicFiles
{
    public const string STATUS_FILENAME = 'status.json';
    public const string HEARTBEAT_FILENAME = 'heartbeat.json';
    public const int MAX_HEARTBEAT_INTERVAL_MS = 30000;
    /** Refusal of a test heartbeat command while no coherent pair is published (HIL-1856). */
    public const string NO_PUBLIC_PAIR = 'No public Status pair is published yet.';

    /** At most this many JSON fragments are visited in one serialization portion. */
    private const int MAX_FRAGMENTS_PER_ADVANCE = 64;
    private const int MAX_WRITE_BYTES = 65536;
    private const int TEMP_NAME_BYTES = 16;
    private const int PUBLIC_REVISION_BYTES = 16;
    private const int HEARTBEATS_PER_STALE_INTERVAL = 3;
    /** Enough decimal characters for every signed 64-bit moment. */
    private const int END_RESERVED_BYTES = 20;

    private readonly float $heartbeatIntervalMs;
    private ?StatusPublicSnapshot $published = null;
    private ?StatusPublicHeartbeat $lastHeartbeat = null;
    private ?StatusPublicSnapshot $pending = null;
    private bool $pairCoherent = false;
    private bool $statusInstalled = false;
    private bool $publicationFailed = false;
    /** Set by a test command so the next heartbeat check answers "due" whatever the cadence (HIL-1856) */
    private bool $heartbeatDue = false;
    private ?FsFile $statusTemporary = null;
    private ?FsFile $heartbeatTemporary = null;
    /** @var ?Generator<int, string> Bounded serialization cursor over detached values */
    private ?Generator $fragments = null;
    private int $fragmentOffset = 0;
    private bool $nextFragment = false;
    private int $statusBytes = 0;
    private StatusSilenceObservationStore $silenceStore;
    private ?StatusSilenceObservation $silence = null;
    private bool $silenceSavePending = false;
    private bool $silenceRecovered = false;
    private bool $silenceRecoveryAdvanced = false;
    /** @var ?ArrayIterator<int, StatusPublicSilencePeriod> Bounded cold-pair index cursor */
    private ?ArrayIterator $silenceRecovery = null;
    /** @var array<string, StatusPublicSilencePeriod> Detached recovery index, released after discovery */
    private array $silenceRecoveryIndex = [];
    /** @var array<string, StatusSilenceObservation> Closed durable transit awaiting owner import */
    private array $closedSilences = [];
    private ?int $closingIndex = null;
    private ?int $closingOffset = null;

    /**
     * Configuration is environment-only, so cold freeze needs no database or settings.
     *
     * @throws StatusException When either configured reader interval is invalid or unavailable
     */
    public function __construct()
    {
        if (Hilos::$env === null) {
            throw new StatusException('Public Status requires an initialized environment.');
        }
        try {
            Hilos::$env[EnvConstants::STATUS_POLL_INTERVAL_SECONDS]->int();
            $staleAfterSeconds = Hilos::$env[EnvConstants::STATUS_STALE_AFTER_SECONDS]->int();
        } catch (EnvException $exception) {
            throw new StatusException('Invalid public Status intervals.', 0, $exception);
        }
        $this->silenceStore = new StatusSilenceObservationStore();
        // Floating-point division preserves a positive cadence even for staleAfterSeconds=1.
        $this->heartbeatIntervalMs = min(
            self::MAX_HEARTBEAT_INTERVAL_MS,
            $staleAfterSeconds * (float)TimeConstants::MS_PER_SECOND / self::HEARTBEATS_PER_STALE_INTERVAL,
        );
    }

    /**
     * Replaces unfinished work with the caller's content revision. Closing a silence gap
     * assigns a fresh revision per attempt because its installed end may change on retry.
     *
     * @param StatusPublicSnapshot $snapshot Complete sanitized projection
     * @throws StatusException When an unfinished temporary file cannot be removed
     */
    public function begin(StatusPublicSnapshot $snapshot): void
    {
        $this->cancel();
        $this->pending = $snapshot;
    }

    /** @return bool Whether a full pair is still being written or retried */
    public function isPublishing(): bool
    {
        return $this->pending !== null;
    }

    /** @return ?StatusPublicSnapshot Last successful or recovered pair, absent before the first valid pair */
    public function getSnapshot(): ?StatusPublicSnapshot
    {
        return $this->published;
    }

    /** @return float Existing heartbeat cadence in milliseconds, also used by private observation persistence */
    public function getHeartbeatIntervalMs(): float
    {
        return $this->heartbeatIntervalMs;
    }

    /**
     * Visits at most 64 fragments (one component, candidate, outage header or update per
     * fragment) and appends at most 64 KiB. Completion adds one status rename and one tiny
     * heartbeat write/rename. No whole outage timeline or history is encoded at once.
     *
     * @param int $nowMs Current epoch milliseconds UTC, also used on a heartbeat retry
     * @return ?StatusPublicHeartbeat Receipt for a complete pair, or null while unfinished/idle
     * @throws StatusException When serialization or filesystem publication fails; the generation remains retryable
     */
    public function advance(int $nowMs): ?StatusPublicHeartbeat
    {
        if ($nowMs < 0) {
            throw new StatusException('Public Status requires a valid publication moment.');
        }
        try {
            $this->prepareSilence($nowMs);
            if (!$this->silenceRecovered || $this->pending === null) {
                return null;
            }
            if ($this->silence?->endedAt === null && $this->silence !== null && $this->closingIndex === null) {
                $snapshot = $this->pending;
                // Mint before changing staging state, so entropy refusal leaves the open fact intact.
                $revision = RandomHelper::secureHex(self::PUBLIC_REVISION_BYTES);
                $this->begin($snapshot);
                $periods = $snapshot->silencePeriods;
                $lower = 0;
                $upper = count($periods);
                while ($lower < $upper) {
                    $middle = intdiv($lower + $upper, 2);
                    if ($periods[$middle]->startedAt < $this->silence->startedAt
                        || ($periods[$middle]->startedAt === $this->silence->startedAt
                            && strcmp($periods[$middle]->id, $this->silence->id) < 0)) {
                        $lower = $middle + 1;
                    } else {
                        $upper = $middle;
                    }
                }
                $this->closingIndex = $lower;
                // Installation replaces this provisional end; getSnapshot() changes only after receipt.
                array_splice($periods, $lower, 0, [$this->silence->complete($nowMs)->toPublicPeriod()]);
                // A reader holding an earlier installed end must reject this attempt's heartbeat.
                $this->pending = new StatusPublicSnapshot(
                    $revision, $snapshot->generatedAt, $snapshot->pollIntervalSeconds, $snapshot->staleAfterSeconds,
                    $snapshot->components, $snapshot->candidates, $snapshot->outages, $snapshot->scheduledWorks,
                    $snapshot->maintenancePeriods, $snapshot->sourceSchemaVersion, $periods,
                );
            }
            if (!$this->statusInstalled) {
                if ($this->fragments === null) {
                    $this->discardTemporaryFiles();
                    $this->statusTemporary = $this->createTemporary();
                    $this->fragments = $this->jsonFragments($this->pending);
                    $this->fragmentOffset = 0;
                    $this->nextFragment = false;
                    $this->statusBytes = 0;
                }
                $bytes = '';
                $finished = false;
                $byteLimit = self::MAX_WRITE_BYTES - self::END_RESERVED_BYTES;
                for ($step = 0; $step < self::MAX_FRAGMENTS_PER_ADVANCE - 1 && strlen($bytes) < $byteLimit; ++$step) {
                    if ($this->nextFragment) {
                        $this->fragments->next();
                        $this->nextFragment = false;
                    }
                    if (!$this->fragments->valid()) {
                        $finished = true;
                        break;
                    }
                    $fragment = $this->fragments->current();
                    $part = substr($fragment, $this->fragmentOffset, $byteLimit - strlen($bytes));
                    $bytes .= $part;
                    $this->fragmentOffset += strlen($part);
                    if ($this->fragmentOffset === strlen($fragment)) {
                        $this->nextFragment = true;
                        $this->fragmentOffset = 0;
                    }
                }
                $this->appendComplete($this->statusTemporary, $bytes, $this->statusBytes);
                $this->statusBytes += strlen($bytes);
                if (!$finished) {
                    return null;
                }
                if ($this->closingIndex !== null) {
                    $closed = $this->silence->complete($nowMs)->toPublicPeriod();
                    FsPath::writeAt(
                        $this->statusTemporary->getPath(), $this->closingOffset,
                        str_pad((string)$closed->endedAt, self::END_RESERVED_BYTES),
                    );
                    $periods = $this->pending->silencePeriods;
                    $periods[$this->closingIndex] = $closed;
                    $this->pending = $this->pending->withSilencePeriods($periods);
                }
                FsPath::publish($this->statusTemporary->getPath(), Hilos::$fs->status[self::STATUS_FILENAME]->getPath());
                $this->statusTemporary = null;
                $this->fragments = null;
                $this->statusInstalled = true;
                $this->pairCoherent = false;
            }
            $receipt = $this->publishHeartbeat($this->pending->revision, $nowMs);
            $this->published = $this->pending;
            $this->pending = null;
            $this->statusInstalled = false;
            $this->pairCoherent = true;
            $this->publicationFailed = false;
            $this->lastHeartbeat = $receipt;
            $this->heartbeatDue = false;
            if ($this->closingIndex !== null) {
                $this->silence = $this->silence->complete($receipt->aliveAt);
                $this->silenceSavePending = true;
                $this->closingIndex = null;
                $this->closingOffset = null;
            }
            return $receipt;
        } catch (FsException | JsonException | RandomException | StatusException $exception) {
            // A failed append may have written a prefix. Restart that temp file on the next
            // call. An installed status retries its heartbeat unless a gap's end must be rebuilt.
            $this->fragments = null;
            if ($this->closingIndex !== null) {
                // No successful heartbeat: rebuild the reserved end for the retry's own clock.
                $periods = $this->pending->silencePeriods;
                array_splice($periods, $this->closingIndex, 1);
                $this->pending = $this->pending->withSilencePeriods($periods);
                $this->closingIndex = null;
                $this->closingOffset = null;
                $this->statusInstalled = false;
            }
            $this->publicationFailed = true;
            throw new StatusException('Cannot publish public Status files.', 0, $exception);
        }
    }

    /**
     * Private staging may take many passes; the last coherent pair stays alive meanwhile.
     * A failed attempt or an installed mismatched status blocks that old heartbeat until repair.
     * After {@see makeHeartbeatDue()} the cadence is not asked once; the next interval counts
     * from the heartbeat this publishes.
     *
     * @param int $nowMs Current epoch milliseconds UTC
     * @return ?StatusPublicHeartbeat Successful heartbeat receipt, or null before the deadline/without a coherent pair
     * @throws StatusException When a due heartbeat cannot be published
     */
    public function heartbeat(int $nowMs): ?StatusPublicHeartbeat
    {
        if ($nowMs < 0) {
            throw new StatusException('Public Status requires a valid heartbeat moment.');
        }
        if ($this->publicationFailed || !$this->pairCoherent || !$this->silenceRecovered) {
            return null;
        }
        $this->prepareSilence($nowMs);
        if (!$this->silenceRecovered || $this->silence !== null || $this->publicationFailed
            || !$this->pairCoherent || $this->published === null || $this->lastHeartbeat === null
            || (!$this->heartbeatDue && $nowMs - $this->lastHeartbeat->aliveAt < $this->heartbeatIntervalMs)) {
            return null;
        }
        try {
            $receipt = $this->publishHeartbeat($this->published->revision, $nowMs);
            $this->lastHeartbeat = $receipt;
            $this->heartbeatDue = false;
            return $receipt;
        } catch (FsException | JsonException | RandomException $exception) {
            throw new StatusException('Cannot publish public Status heartbeat.', 0, $exception);
        }
    }

    /**
     * Publishes the last coherent pair's heartbeat at a moment the caller aged (test-only, HIL-1856).
     *
     * The heartbeat a reader sees is this one, and so is the one the cadence counts from: the
     * owner's next ordinary heartbeat would overwrite it at once, which is why the owner holds its
     * publication after calling this. The status file is not touched.
     *
     * @param int $aliveAt Aged publication moment in epoch milliseconds UTC
     * @return StatusPublicHeartbeat Receipt of the aged heartbeat
     * @throws StatusException When no coherent pair is published, or the heartbeat cannot be written
     */
    public function publishAgedHeartbeat(int $aliveAt): StatusPublicHeartbeat
    {
        if ($this->publicationFailed || !$this->pairCoherent || $this->published === null || $this->lastHeartbeat === null) {
            throw new StatusException(self::NO_PUBLIC_PAIR);
        }
        try {
            $receipt = $this->publishHeartbeat($this->published->revision, $aliveAt);
        } catch (FsException | JsonException | RandomException $exception) {
            throw new StatusException('Cannot publish public Status heartbeat.', 0, $exception);
        }
        $this->lastHeartbeat = $receipt;
        return $receipt;
    }

    /** Makes the next {@see heartbeat()} publish whatever its cadence says; the next pair or heartbeat clears it (HIL-1856). */
    public function makeHeartbeatDue(): void
    {
        $this->heartbeatDue = true;
    }

    /**
     * Cold-start boundary only: reads and decodes the complete files once. A broken pair
     * never replaces known history and cannot authorize heartbeat-only publication.
     *
     * @return ?StatusPublicSnapshot Last valid pair, or null when none has ever been available
     * @throws StatusException When recovery is requested during an unfinished publication
     */
    public function restore(): ?StatusPublicSnapshot
    {
        if ($this->pending !== null) {
            throw new StatusException('Cancel the unfinished public Status generation before recovery.');
        }
        $this->pairCoherent = false;
        try {
            $snapshot = StatusPublicSnapshot::fromJson(Hilos::$fs->status[self::STATUS_FILENAME]->read());
            $heartbeat = StatusPublicHeartbeat::fromJson(Hilos::$fs->status[self::HEARTBEAT_FILENAME]->read());
            if ($snapshot->revision !== $heartbeat->revision || $snapshot->sourceSchemaVersion !== $heartbeat->sourceSchemaVersion) {
                return $this->published;
            }
        } catch (FsException | StatusException) {
            return $this->published;
        }
        $this->published = $snapshot;
        $this->lastHeartbeat = $heartbeat;
        $this->silenceRecovery = new ArrayIterator($snapshot->silencePeriods);
        // A legacy pair is readable, but must be republished together before any v3 heartbeat.
        $this->pairCoherent = $snapshot->sourceSchemaVersion === StatusPublicSnapshot::SCHEMA_VERSION;
        return $snapshot;
    }

    /**
     * Releases unfinished resources without removing either published file. If status was
     * already replaced, heartbeat-only stays blocked until another full pair succeeds.
     *
     * @throws StatusException When a temporary file cannot be removed; cancellation may be retried
     */
    public function cancel(): void
    {
        $this->fragments = null;
        try {
            $this->discardTemporaryFiles();
        } catch (FsException $exception) {
            throw new StatusException('Cannot remove temporary public Status files.', 0, $exception);
        }
        $this->pending = null;
        $this->statusInstalled = false;
        $this->fragmentOffset = 0;
        $this->nextFragment = false;
        $this->statusBytes = 0;
        $this->closingIndex = null;
        $this->closingOffset = null;
    }

    /**
     * Releases publication and cold-recovery cursors when this owner stops.
     * Durable open facts remain on the shared volume for its successor.
     *
     * @throws StatusException When unfinished publication files cannot be removed
     */
    public function stop(): void
    {
        try {
            $this->cancel();
        } finally {
            $this->silenceStore = new StatusSilenceObservationStore();
            $this->silenceRecovery = null;
            $this->silenceRecoveryIndex = [];
            $this->closedSilences = [];
            $this->silence = null;
            $this->silenceSavePending = false;
            $this->silenceRecovered = false;
            $this->silenceRecoveryAdvanced = false;
        }
    }

    /**
     * Qualifies and persists a gap while the owner is still building its permitted current overlay.
     *
     * @param int $nowMs Proposed publication clock
     * @throws StatusException When durable observation storage is unavailable
     */
    public function observeSilence(int $nowMs): void
    {
        $this->prepareSilence($nowMs, false);
    }

    /**
     * The test owner calls this after aging the heartbeat, before acknowledging its fixture.
     *
     * @param int $nowMs Qualification clock, strictly past the last pair's stale threshold
     * @return StatusSilenceObservation The stable open fact reserved by that synthetic silence
     * @throws StatusException When recovery or durable pending storage is unavailable
     */
    public function reserveSilence(int $nowMs): StatusSilenceObservation
    {
        $this->prepareSilence($nowMs);
        return $this->silence ?? throw new StatusException('Silence recovery is still in progress. Try again shortly.');
    }

    /** @return ?StatusSilenceObservation Current unimported observation, open until a successful pair */
    public function getSilenceObservation(): ?StatusSilenceObservation
    {
        return $this->silence;
    }

    /** @return list<StatusSilenceObservation> At most one import portion of closed durable transit */
    public function getClosedSilenceObservations(): array
    {
        return array_values(array_slice($this->closedSilences, 0, StatusSilenceObservationStore::MAX_FILES_PER_PASS, true));
    }

    /**
     * @param string $id Public transit identity
     * @return ?StatusSilenceObservation Matching closed durable transit, or null
     */
    public function findClosedSilence(string $id): ?StatusSilenceObservation
    {
        return $this->closedSilences[$id] ?? null;
    }

    /**
     * @return Generator<string, StatusPublicSilencePeriod> Fresh detached cursor; the builder bounds consumption
     * @throws StatusException When a retained observation is not closed
     */
    public function getTransitSilencePeriods(): Generator
    {
        foreach ($this->closedSilences as $id => $fact) {
            yield $id => $fact->toPublicPeriod();
        }
    }

    /**
     * @param StatusSilenceObservation $observation Exact committed facts
     * @throws StatusException When the transit cannot be removed after commit
     */
    public function removeCommittedSilence(StatusSilenceObservation $observation): void
    {
        $this->silenceStore->remove($observation);
        unset($this->closedSilences[$observation->id]);
    }

    /** @return bool Whether cold private transit has been completely discovered */
    public function hasRecoveredSilence(): bool
    {
        return $this->silenceRecovered;
    }

    /**
     * One bounded recovery pass; no DB access, including under freeze.
     *
     * @throws StatusException When transit is damaged, inconsistent or unavailable
     * @throws RandomException When saving a recovered closure cannot obtain entropy
     */
    public function advanceSilenceRecovery(): void
    {
        $this->silenceRecoveryAdvanced = true;
        if ($this->silenceSavePending) {
            $this->silenceStore->save($this->silence);
            $this->silenceSavePending = false;
            if ($this->silence->endedAt !== null) {
                $this->closedSilences[$this->silence->id] = $this->silence;
                $this->silence = null;
            }
        }
        if ($this->silenceRecovered) {
            return;
        }
        if ($this->silenceRecovery !== null) {
            for ($step = 0; $step < StatusSilenceObservationStore::MAX_FILES_PER_PASS && $this->silenceRecovery->valid(); ++$step) {
                $period = $this->silenceRecovery->current();
                $this->silenceRecoveryIndex[$period->id] = $period;
                $this->silenceRecovery->next();
            }
            if ($this->silenceRecovery->valid()) {
                return;
            }
            $this->silenceRecovery = null;
        }
        try {
            foreach ($this->silenceStore->discover() as $name) {
                $fact = $this->silenceStore->load($name);
                $period = $this->silenceRecoveryIndex[$fact->id] ?? null;
                if ($fact->endedAt === null && $period !== null) {
                    if ($period->startedAt !== $fact->startedAt || $period->endedAt > $this->lastHeartbeat->aliveAt) {
                        throw new StatusException('Recovered silence disagrees with the coherent public pair.');
                    }
                    $fact = $fact->complete($period->endedAt);
                    $this->silenceStore->save($fact);
                }
                if ($fact->endedAt !== null) {
                    $this->closedSilences[$fact->id] = $fact;
                    if ($this->silence?->id === $fact->id) {
                        $this->silence = null;
                    }
                } elseif ($this->silence !== null && $this->silence->id !== $fact->id) {
                    throw new StatusException('Several unfinished silence observations require reconciliation.');
                } else {
                    $this->silence = $fact;
                }
            }
        } catch (StatusException $failure) {
            // The failed record must be visited again; a cursor past it cannot authorize a heartbeat.
            $this->silenceStore = new StatusSilenceObservationStore();
            $this->silenceRecoveryAdvanced = false;
            throw $failure;
        }
        $this->silenceRecovered = !$this->silenceStore->isDiscovering();
        if ($this->silenceRecovered) {
            $this->silenceRecoveryIndex = [];
        }
    }

    /**
     * Qualifies the gap before any new successful heartbeat can replace its start.
     *
     * @param int $nowMs Proposed successful publication clock
     * @param bool $beginGeneration Whether a gap may start a full copy of the last published current facts
     * @throws StatusException When durable pending storage or publication setup fails
     */
    private function prepareSilence(int $nowMs, bool $beginGeneration = true): void
    {
        try {
            // The owner may already have advanced this pass before its bounded DB import.
            if (!$this->silenceRecoveryAdvanced) {
                $this->advanceSilenceRecovery();
            }
            $this->silenceRecoveryAdvanced = false;
            if (!$this->silenceRecovered) {
                return;
            }
            if ($this->silence === null && $this->lastHeartbeat !== null && $this->published !== null
                && $nowMs - $this->lastHeartbeat->aliveAt > $this->published->staleAfterSeconds * TimeConstants::MS_PER_SECOND) {
                $this->silence = StatusSilenceObservation::start($this->lastHeartbeat->aliveAt);
                $this->silenceSavePending = true;
            }
            if ($this->silenceSavePending) {
                $this->advanceSilenceRecovery();
            }
            if ($beginGeneration && $this->silence !== null && $this->pending === null && $this->published !== null) {
                $base = $this->published;
                $this->begin(new StatusPublicSnapshot(
                    RandomHelper::secureHex(self::PUBLIC_REVISION_BYTES), $nowMs, $base->pollIntervalSeconds, $base->staleAfterSeconds,
                    $base->components, $base->candidates, $base->outages, $base->scheduledWorks, $base->maintenancePeriods,
                    silencePeriods: $base->silencePeriods,
                ));
            }
        } catch (RandomException $failure) {
            throw new StatusException('Cannot persist public Status silence.', previous: $failure);
        }
    }

    /** @return FsFile Exclusively created empty temp on the status volume */
    private function createTemporary(): FsFile
    {
        Hilos::$fs->status->ensureDirectory();
        $file = Hilos::$fs->status['.status-' . RandomHelper::secureHex(self::TEMP_NAME_BYTES) . '.tmp'];
        if (!FsPath::createExclusive($file->getPath(), '')) {
            throw new StatusException('Public Status temporary name is already in use.');
        }
        return $file;
    }

    /**
     * @param string $revision Revision whose whole status file is already installed
     * @param int $nowMs Publication epoch milliseconds UTC
     * @return StatusPublicHeartbeat Receipt only after the atomic heartbeat rename
     * @throws StatusException When the heartbeat metadata or temp name is invalid
     */
    private function publishHeartbeat(string $revision, int $nowMs): StatusPublicHeartbeat
    {
        $receipt = new StatusPublicHeartbeat($revision, $nowMs);
        $this->heartbeatTemporary?->unlink();
        $this->heartbeatTemporary = $this->createTemporary();
        $this->appendComplete($this->heartbeatTemporary, json_encode($receipt->toArray(), JSON_THROW_ON_ERROR), 0);
        FsPath::publish($this->heartbeatTemporary->getPath(), Hilos::$fs->status[self::HEARTBEAT_FILENAME]->getPath());
        $this->heartbeatTemporary = null;
        return $receipt;
    }

    /**
     * FsFile append reports failed writes; size checks also refuse a short write or a lost
     * staging prefix before it can be renamed into a public file.
     *
     * @param FsFile $file Private staging file
     * @param string $bytes Bounded portion to append
     * @param int $expectedBefore Expected staging size before the append
     * @throws StatusException When a short or interrupted write would yield incomplete JSON
     */
    private function appendComplete(FsFile $file, string $bytes, int $expectedBefore): void
    {
        $path = $file->getPath();
        clearstatcache(true, $path);
        if (FsPath::size($path) !== $expectedBefore) {
            throw new StatusException('Public Status staging file is incomplete.');
        }
        $file->append($bytes);
        clearstatcache(true, $path);
        if (FsPath::size($path) !== $expectedBefore + strlen($bytes)) {
            throw new StatusException('Public Status staging write was incomplete.');
        }
    }

    /** Deletes only this instance's temps, never a directory entry belonging to the guard. */
    private function discardTemporaryFiles(): void
    {
        try {
            $this->statusTemporary?->unlink();
            $this->statusTemporary = null;
        } finally {
            $this->heartbeatTemporary?->unlink();
            $this->heartbeatTemporary = null;
        }
    }

    /**
     * @param StatusPublicSnapshot $snapshot Detached generation
     * @return Generator<int, string> Literal v3 JSON with one reserved closing boundary
     */
    private function jsonFragments(StatusPublicSnapshot $snapshot): Generator
    {
        $position = 0;
        foreach ($this->contentFragments($snapshot) as $fragment) {
            if ($fragment === null) {
                $this->closingOffset = $position;
                $fragment = str_repeat(' ', self::END_RESERVED_BYTES);
            }
            yield $fragment;
            $position += strlen($fragment);
        }
    }

    /**
     * @param StatusPublicSnapshot $snapshot Detached generation, including its provisional closed value
     * @return Generator<int, ?string> Null reserves the exact end, materialized only at installation
     */
    private function contentFragments(StatusPublicSnapshot $snapshot): Generator
    {
        yield substr(json_encode([
            'schemaVersion' => StatusPublicSnapshot::SCHEMA_VERSION,
            'revision' => $snapshot->revision,
            'generatedAt' => $snapshot->generatedAt,
            'config' => ['pollIntervalSeconds' => $snapshot->pollIntervalSeconds, 'staleAfterSeconds' => $snapshot->staleAfterSeconds],
        ], JSON_THROW_ON_ERROR), 0, -1) . ',"components":[';
        foreach ($snapshot->components as $index => $component) {
            yield ($index === 0 ? '' : ',') . json_encode($component->toArray(), JSON_THROW_ON_ERROR);
        }
        yield '],"candidates":[';
        foreach ($snapshot->candidates as $index => $candidate) {
            yield ($index === 0 ? '' : ',') . json_encode($candidate->toArray(), JSON_THROW_ON_ERROR);
        }
        yield '],"outages":[';
        foreach ($snapshot->outages as $index => $outage) {
            yield ($index === 0 ? '' : ',') . substr(json_encode([
                'id' => $outage->id, 'componentKey' => $outage->componentKey, 'severity' => $outage->severity->value,
                'detectedAt' => $outage->detectedAt, 'publishedAt' => $outage->publishedAt, 'resolvedAt' => $outage->resolvedAt,
            ], JSON_THROW_ON_ERROR), 0, -1) . ',"updates":[';
            foreach ($outage->updates as $updateIndex => $update) {
                yield ($updateIndex === 0 ? '' : ',') . json_encode($update->toArray(), JSON_THROW_ON_ERROR);
            }
            yield ']}';
        }
        yield '],"scheduledWorks":[';
        foreach ($snapshot->scheduledWorks as $index => $work) {
            yield ($index === 0 ? '' : ',') . json_encode($work->toArray(), JSON_THROW_ON_ERROR);
        }
        yield '],"maintenancePeriods":[';
        foreach ($snapshot->maintenancePeriods as $index => $period) {
            yield ($index === 0 ? '' : ',') . json_encode($period->toArray(), JSON_THROW_ON_ERROR);
        }
        yield '],"silencePeriods":[';
        foreach ($snapshot->silencePeriods as $index => $period) {
            if ($index === $this->closingIndex) {
                yield ($index === 0 ? '' : ',') . '{"id":"' . $period->id . '","startedAt":' . $period->startedAt . ',"endedAt":';
                yield null;
                yield '}';
            } else {
                yield ($index === 0 ? '' : ',') . json_encode($period->toArray(), JSON_THROW_ON_ERROR);
            }
        }
        yield ']}';
    }
}
