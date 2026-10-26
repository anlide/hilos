<?php

declare(strict_types=1);

namespace Hilos\Status;

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

    /** At most this many JSON fragments are visited in one serialization portion. */
    private const int MAX_FRAGMENTS_PER_ADVANCE = 64;
    private const int MAX_WRITE_BYTES = 65536;
    private const int TEMP_NAME_BYTES = 16;
    private const int HEARTBEATS_PER_STALE_INTERVAL = 3;

    private readonly float $heartbeatIntervalMs;
    private ?StatusPublicSnapshot $published = null;
    private ?StatusPublicHeartbeat $lastHeartbeat = null;
    private ?StatusPublicSnapshot $pending = null;
    private bool $pairCoherent = false;
    private bool $statusInstalled = false;
    private bool $publicationFailed = false;
    private ?FsFile $statusTemporary = null;
    private ?FsFile $heartbeatTemporary = null;
    /** @var ?Generator<int, string> Bounded serialization cursor over detached values */
    private ?Generator $fragments = null;
    private int $fragmentOffset = 0;
    private bool $nextFragment = false;
    private int $statusBytes = 0;

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
        // Floating-point division preserves a positive cadence even for staleAfterSeconds=1.
        $this->heartbeatIntervalMs = min(
            self::MAX_HEARTBEAT_INTERVAL_MS,
            $staleAfterSeconds * (float)TimeConstants::MS_PER_SECOND / self::HEARTBEATS_PER_STALE_INTERVAL,
        );
    }

    /**
     * Replaces unfinished work; the caller assigns a new revision only when content changes.
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
        if ($this->pending === null) {
            return null;
        }
        try {
            if (!$this->statusInstalled) {
                if ($this->fragments === null) {
                    $this->discardTemporaryFiles();
                    $this->statusTemporary = $this->createTemporary();
                    $this->fragments = self::jsonFragments($this->pending);
                    $this->fragmentOffset = 0;
                    $this->nextFragment = false;
                    $this->statusBytes = 0;
                }
                $bytes = '';
                $finished = false;
                for ($step = 0; $step < self::MAX_FRAGMENTS_PER_ADVANCE && strlen($bytes) < self::MAX_WRITE_BYTES; ++$step) {
                    if ($this->nextFragment) {
                        $this->fragments->next();
                        $this->nextFragment = false;
                    }
                    if (!$this->fragments->valid()) {
                        $finished = true;
                        break;
                    }
                    $fragment = $this->fragments->current();
                    $part = substr($fragment, $this->fragmentOffset, self::MAX_WRITE_BYTES - strlen($bytes));
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
            return $receipt;
        } catch (FsException | JsonException | RandomException | StatusException $exception) {
            // A failed append may have written a prefix. Restart that temp file on the next
            // call; once status was installed, retry only its matching heartbeat instead.
            $this->fragments = null;
            $this->publicationFailed = true;
            throw new StatusException('Cannot publish public Status files.', 0, $exception);
        }
    }

    /**
     * Private staging may take many passes; the last coherent pair stays alive meanwhile.
     * A failed attempt or an installed mismatched status blocks that old heartbeat until repair.
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
        if ($this->publicationFailed || !$this->pairCoherent || $this->published === null || $this->lastHeartbeat === null
            || $nowMs - $this->lastHeartbeat->aliveAt < $this->heartbeatIntervalMs) {
            return null;
        }
        try {
            $receipt = $this->publishHeartbeat($this->published->revision, $nowMs);
            $this->lastHeartbeat = $receipt;
            return $receipt;
        } catch (FsException | JsonException | RandomException $exception) {
            throw new StatusException('Cannot publish public Status heartbeat.', 0, $exception);
        }
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
            if ($snapshot->revision !== $heartbeat->revision) {
                return $this->published;
            }
        } catch (FsException | StatusException) {
            return $this->published;
        }
        $this->published = $snapshot;
        $this->lastHeartbeat = $heartbeat;
        $this->pairCoherent = true;
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
     * @return Generator<int, string> Literal v1 JSON fragments in source order
     */
    private static function jsonFragments(StatusPublicSnapshot $snapshot): Generator
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
        yield '],"scheduledWorks":[],"maintenancePeriods":[]}';
    }
}
