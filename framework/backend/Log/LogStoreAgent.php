<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesReplyDTO;
use Throwable;

/**
 * Node-local monopolistic agent owning the log directory (HIL-753).
 *
 * A concrete framework agent, the way {@see LogRotationAgent} is: owning a directory of files
 * looks the same in every project, so there is nothing for a project to redefine — it registers
 * the pair in Hilos::AGENTS under {@see HilosAgentType::HILOS_LOG_STORE} and is done. One replica
 * per node, in a monopolistic worker, because the work is blocking file I/O and a node needs
 * exactly one reader of its own directory.
 *
 * What it owns is the DIRECTORY, not the lines: a process still writes its own log file through
 * the Logger. Routing every line through an agent would turn logging in the whole framework upside
 * down and buy nothing.
 *
 * The walk is split because the two halves change at different rates. Live files at the log root
 * move every second, so {@see LogStoreReader::readLiveFiles()} resamples them every
 * {@see self::LIVE_SCAN_INTERVAL_SECONDS}; the archive only moves when rotation or cleanup runs,
 * so the full {@see LogStoreReader::read()} runs every {@see self::FULL_SCAN_INTERVAL_SECONDS}.
 * Rotation announces itself: {@see LogRotator::rotate()} renames a live file away whole, so a live
 * key that disappeared or shrank forces the full walk out of turn — otherwise the index would deny
 * the new batch for up to a minute.
 *
 * It is deliberately quiet. It writes into the very directory it measures, so only a CHANGE of
 * availability reaches the log at all, one line per crossing; keys and batches coming and going are
 * DEBUG, visible under investigation and nowhere else.
 */
final class LogStoreAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_STORE;

    /**
     * Reads addressed to this node's replica: the node id in the payload is the address, and the
     * router turns a foreign one into a delivery over the peer channel (HIL-757).
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::LOGS_AGENT_READ_LINES => [
            AgentSignalConfigKey::NODE_FIELD => LogsReadLinesActionDTO::nodeId,
            AgentSignalConfigKey::DTO => LogsReadLinesSignalData::class,
        ],
    ];

    /** @var int Lines one page of the viewer holds, the number the mockup shows */
    private const int READ_PAGE_LINES = 200;

    /** @var float Minimum seconds between two live walks of the log root */
    private const float LIVE_SCAN_INTERVAL_SECONDS = 5.0;

    /** @var float Minimum seconds between two full walks, archive included */
    private const float FULL_SCAN_INTERVAL_SECONDS = 60.0;

    private LogStoreReader $reader;

    /** @var LogStoreSnapshot Result of the last full walk, the archive half of every live resample */
    private LogStoreSnapshot $snapshot;

    private NodeLogIndex $index;

    /** @var ?NodeLogIndexDelta Difference the last walk made, or null before the agent has started */
    private ?NodeLogIndexDelta $lastDelta = null;

    /** @var ?string Cluster node this agent measures, or null in a single-node installation */
    private ?string $nodeId = null;

    /** @var array<string, LogGrowthWindow> Key → its rolling day window */
    private array $windows = [];

    /** @var array<string, int> Live basename → its weight at the last walk, the rotation tripwire */
    private array $liveKeyBytes = [];

    /** @var float Timestamp of the last live walk, for throttling */
    private float $lastLiveScanAt = 0.0;

    /** @var float Timestamp of the last full walk, for throttling */
    private float $lastFullScanAt = 0.0;

    /**
     * Builds the reader, learns which node this is, and takes the first full walk as the baseline.
     *
     * @throws EnvException When the cluster-enabled flag or a cluster environment value cannot be read
     * @throws ClusterConfigurationException When cluster mode is on but the local node config is missing or invalid
     */
    public function onStart(): void
    {
        $this->reader = LogStoreReader::fromEnv();
        $cluster = Hilos::$cluster;
        $clustered = $cluster !== null && $cluster->isEnabled();
        $this->nodeId = $clustered ? $cluster->identity()->nodeId : null;
        // Seeded as readable so the baseline walk stays silent on a healthy store and still says
        // one line on a broken one: a start is not a crossing, an unreadable directory is news.
        $this->index = new NodeLogIndex($this->nodeId, true, time(), [], [], [], []);
        $this->snapshot = LogStoreSnapshot::unavailable();
        $this->lastFullScanAt = microtime(true);
        $this->lastLiveScanAt = $this->lastFullScanAt;
        $this->walkStore(time());
    }

    /**
     * Throttle only: it decides WHICH walk is due and does none of the work itself.
     */
    public function onTick(): void
    {
        $now = microtime(true);
        if ($now - $this->lastFullScanAt >= self::FULL_SCAN_INTERVAL_SECONDS) {
            $this->lastFullScanAt = $now;
            $this->lastLiveScanAt = $now;
            $this->walkStore((int)$now);

            return;
        }
        if ($now - $this->lastLiveScanAt < self::LIVE_SCAN_INTERVAL_SECONDS) {
            return;
        }
        $this->lastLiveScanAt = $now;

        $this->walkLiveFiles((int)$now);
    }

    /**
     * Nothing owned to release: no file is held open, the index is derived, and a restart costs
     * only the day windows.
     */
    public function onStop(): void
    {
        // No-op.
    }

    /**
     * Answers one read of one file of this node's log directory.
     *
     * The blocking file walk is legitimate here and nowhere else: this agent is monopolistic and
     * node-local (HIL-753), so one reader of one directory is exactly what it is for.
     *
     * @param AgentSignalData $data Signal data (container with inner payload)
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws AgentUnknownSignalException When the agent is reached by a signal it does not own
     * @throws InvalidAgentSignalPayloadException When the read frame carries the wrong payload
     * @throws InvalidArgumentException When the answer to the read cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::LOGS_AGENT_READ_LINES:
                $request = $data->data;
                if (!$request instanceof LogsReadLinesSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, LogsReadLinesSignalData::class, $request);
                }

                $this->handleReadLines($request);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * This node's log index as of the last walk.
     *
     * @return NodeLogIndex Current index, unavailable when the directory could not be read
     */
    public function index(): NodeLogIndex
    {
        return $this->index;
    }

    /**
     * What the last walk changed.
     *
     * @return ?NodeLogIndexDelta Difference to the index before it, or null before the agent has started
     */
    public function lastDelta(): ?NodeLogIndexDelta
    {
        return $this->lastDelta;
    }

    /**
     * Walk the root and the archive, refresh the day windows, and publish the new index.
     *
     * The clock is a parameter rather than a reading: the walk is the agent's work and the tick is
     * only its throttle, so what a walk measures does not depend on when it was scheduled.
     *
     * The windows are fed here and not on the live walk: they measure everything a key occupies —
     * live file plus every batch of it — because measuring the live file alone would read a
     * rotation as the key being emptied.
     *
     * @param int $now Unix timestamp to stamp this walk with
     */
    public function walkStore(int $now): void
    {
        $this->snapshot = $this->reader->read();
        $this->publish($this->snapshot, $now, true);
    }

    /**
     * Walk the log root alone and lay it over the batches the last full walk found.
     *
     * Three things send it to the full walk instead, out of turn. The root itself has gone out of
     * reach. A live key vanished or shrank, which means {@see LogRotator::rotate()} has just renamed
     * it into a batch whole, and until the archive is re-read the index would deny the batch exists.
     * Or the last full walk failed: laying fresh live files over an unavailable snapshot would
     * publish a full list of keys under `available` false, and retrying the archive here brings
     * recovery back to seconds instead of a minute.
     *
     * @param int $now Unix timestamp to stamp this walk with
     */
    public function walkLiveFiles(int $now): void
    {
        $liveFiles = $this->reader->readLiveFiles();
        if ($liveFiles === null || !$this->snapshot->available || $this->rotationHappened($liveFiles)) {
            $this->lastFullScanAt = microtime(true);
            $this->walkStore($now);

            return;
        }

        $this->publish($this->snapshot->withLiveFiles($liveFiles), $now, false);
    }

    /**
     * Reads the page the viewer asked for and acks the browser that is waiting for it.
     *
     * The whole read is guarded because this agent is the LAST step of somebody else's action:
     * {@see AbstractHilosLogsViewPage} deferred its ack when it handed the request over, so a
     * failure that only reached the log would leave the browser waiting out its own timeout with
     * the reason recorded where the person waiting cannot see it.
     *
     * An unreadable file is not such a failure and is not treated as one: a missing file, a batch
     * rotation carried off and a path the traversal guard refused all come back as a successful
     * page with {@see LogsReadLinesReplyDTO::$readable} false, the way the whole log index answers.
     *
     * @param LogsReadLinesSignalData $request Read request, carrying whom to answer
     * @throws InvalidArgumentException When the success or failure ack cannot be named
     */
    private function handleReadLines(LogsReadLinesSignalData $request): void
    {
        if ($request->requestId === null) {
            // Nothing to answer: an untracked read has no ack to correlate, and reading a file for
            // a reply nobody receives is work spent on nobody.
            $this->logAgentWarning('Ignoring an untracked log read: no request id to answer on');

            return;
        }

        try {
            $this->sendActionSuccess(
                $request->acceptKey,
                $request->action,
                $request->requestId,
                LogsReadLinesReplyDTO::fromPage($this->readPage($request)),
            );
        } catch (Throwable $e) {
            $this->logAgentError('Log read failed: ' . $e->getMessage());
            $this->sendActionFail($request->acceptKey, $request->action, $request->requestId, $e->getMessage());
        }
    }

    /**
     * Reads the requested slice through the shared reader.
     *
     * Reading runs backwards and only backwards: the first page and the Earlier button are the
     * same query, with and without a cursor. Following the end of a live file is a different
     * mechanism and a different leaf (HIL-389).
     *
     * @param LogsReadLinesSignalData $request Read request naming the file and the slice
     * @return LogLinePage Matched lines, or an unavailable page when the request names no file
     */
    private function readPage(LogsReadLinesSignalData $request): LogLinePage
    {
        $relativePath = $this->relativePath($request);
        if ($relativePath === null) {
            $this->logAgentWarning("Ignoring a log read that names no file: source '{$request->source}'");

            return LogLinePage::unavailable();
        }

        return LogLineReader::fromEnv()->read($relativePath, new LogReadQuery(
            LogReadQuery::ANCHOR_TAIL,
            $request->cursor,
            self::READ_PAGE_LINES,
            $request->level,
            $request->substring,
        ));
    }

    /**
     * Turns the structural name of a file into its path under the log root.
     *
     * The browser names a source, a batch stamp and a stream, never a path - so this is where the
     * archive layout is known, and it is the only place the wire's unix stamp meets the directory
     * name rotation writes ({@see LogRotationConstants::TIMESTAMP_FORMAT}).
     *
     * @param LogsReadLinesSignalData $request Read request naming the file
     * @return ?string Path relative to the log root, or null when the request names no file at all
     */
    private function relativePath(LogsReadLinesSignalData $request): ?string
    {
        if ($request->source === LogsReadLinesActionDTO::SOURCE_LIVE) {
            return $request->stream;
        }
        if ($request->source !== LogsReadLinesActionDTO::SOURCE_BATCH || $request->batchTimestamp === null) {
            return null;
        }

        return LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME
            . '/' . date(LogRotationConstants::TIMESTAMP_FORMAT, $request->batchTimestamp)
            . '/' . $request->stream;
    }

    /**
     * Whether a live key known to the last walk has gone or lost weight.
     *
     * @param array{daemon: array<string, int>, agent: array<string, int>, worker: array<string, int>,
     *     workerMonopolistic: array<string, int>} $liveFiles Classified basename → size in bytes
     *
     * @return bool True when a known live key vanished or shrank, so a batch has just been made
     */
    private function rotationHappened(array $liveFiles): bool
    {
        $current = self::flattenLiveFiles($liveFiles);
        foreach ($this->liveKeyBytes as $name => $bytes) {
            if (!isset($current[$name]) || $current[$name] < $bytes) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn a snapshot into the published index, its delta, and the log lines it deserves.
     *
     * @param LogStoreSnapshot $snapshot Snapshot to publish
     * @param int $sampledAt Unix timestamp of the walk
     * @param bool $full Whether this came from a full walk, the only kind that feeds the windows
     */
    private function publish(LogStoreSnapshot $snapshot, int $sampledAt, bool $full): void
    {
        $keys = $snapshot->keys();
        if ($full) {
            $this->refreshWindows($snapshot->available, $keys, $sampledAt);
        }

        $growth = [];
        foreach ($keys as $key) {
            $growth[$key->key] = ($this->windows[$key->key] ?? null)?->growthPerDay($sampledAt);
        }

        $previous = $this->index;
        $this->index = new NodeLogIndex(
            nodeId: $this->nodeId,
            available: $snapshot->available,
            sampledAt: $sampledAt,
            batches: $snapshot->batches(),
            keys: $keys,
            workers: $snapshot->workers(),
            growthBytesPerDay: $growth,
        );
        $this->lastDelta = self::diff($previous, $this->index);
        $this->rememberLiveKeys($snapshot);
        $this->reportChanges($this->index, $this->lastDelta);
    }

    /**
     * Feed every key's window with its current total weight, and drop the windows of dead keys.
     *
     * An unreadable store breaks every series instead: for the time the directory could not be
     * read there is no honest figure, so the day column goes back to a dash.
     *
     * @param bool $available Whether this walk could read the store
     * @param list<LogKeySummary> $keys Keys the walk found
     * @param int $sampledAt Unix timestamp of the walk
     */
    private function refreshWindows(bool $available, array $keys, int $sampledAt): void
    {
        if (!$available) {
            foreach ($this->windows as $window) {
                $window->reset();
            }

            return;
        }

        $seen = [];
        foreach ($keys as $key) {
            $seen[$key->key] = true;
            ($this->windows[$key->key] ??= new LogGrowthWindow())->addSample($sampledAt, $key->totalBytes);
        }
        // A key gone from the store entirely — no live file, no batch — takes its window with it,
        // or the memory of every dead agent's log would be kept forever.
        foreach (array_keys($this->windows) as $name) {
            if (!isset($seen[$name])) {
                unset($this->windows[$name]);
            }
        }
    }

    /**
     * Remember the live weights this walk saw, as the tripwire the next live walk reads.
     *
     * Live weights, not key totals: a key that has been rotated before carries its batches in the
     * total, and comparing that against a live listing would read every such key as shrunk.
     *
     * @param LogStoreSnapshot $snapshot Snapshot the walk produced
     */
    private function rememberLiveKeys(LogStoreSnapshot $snapshot): void
    {
        $this->liveKeyBytes = self::flattenLiveFiles($snapshot->liveFiles());
    }

    /**
     * Write the one line a crossing deserves, and the DEBUG lines for keys and batches.
     *
     * @param NodeLogIndex $current Index this walk produced
     * @param NodeLogIndexDelta $delta Difference to the index before it
     */
    private function reportChanges(NodeLogIndex $current, NodeLogIndexDelta $delta): void
    {
        if ($delta->availabilityChanged) {
            if ($current->available) {
                $this->logAgentInfo('Log store available again');
            } else {
                $this->logAgentError('Log store unavailable: the log directory is not configured or cannot be listed');
            }
        }
        foreach ($delta->appearedKeys as $key) {
            $this->logAgentDebug("Log store: key {$key} appeared");
        }
        foreach ($delta->vanishedKeys as $key) {
            $this->logAgentDebug("Log store: key {$key} vanished");
        }
        foreach ($delta->appearedBatchTimestamps as $timestamp) {
            $this->logAgentDebug("Log store: batch {$timestamp} appeared");
        }
    }

    /**
     * Fold a classified live listing into one basename → size map.
     *
     * @param array{daemon: array<string, int>, agent: array<string, int>, worker: array<string, int>,
     *     workerMonopolistic: array<string, int>} $liveFiles Classified basename → size in bytes
     *
     * @return array<string, int> Basename → size in bytes, classes merged
     */
    private static function flattenLiveFiles(array $liveFiles): array
    {
        $flat = [];
        foreach ($liveFiles as $classFiles) {
            foreach ($classFiles as $name => $size) {
                $flat[$name] = $size;
            }
        }

        return $flat;
    }

    /**
     * Difference between two indexes: what appeared, grew, vanished, and whether the store changed side.
     *
     * @param NodeLogIndex $previous Older index
     * @param NodeLogIndex $current Newer index
     *
     * @return NodeLogIndexDelta What the newer index has that the older did not, and the reverse
     */
    private static function diff(NodeLogIndex $previous, NodeLogIndex $current): NodeLogIndexDelta
    {
        $before = self::keyBytes($previous);
        $after = self::keyBytes($current);

        $appeared = [];
        $grown = [];
        foreach ($after as $key => $bytes) {
            if (!isset($before[$key])) {
                $appeared[] = $key;

                continue;
            }
            if ($bytes > $before[$key]) {
                $grown[$key] = $bytes - $before[$key];
            }
        }
        $vanished = [];
        foreach (array_keys($before) as $key) {
            if (!isset($after[$key])) {
                $vanished[] = $key;
            }
        }

        $batchesBefore = self::batchTimestamps($previous);
        $batchesAfter = self::batchTimestamps($current);

        return new NodeLogIndexDelta(
            appearedKeys: $appeared,
            vanishedKeys: $vanished,
            grownKeys: $grown,
            appearedBatchTimestamps: array_values(array_diff($batchesAfter, $batchesBefore)),
            vanishedBatchTimestamps: array_values(array_diff($batchesBefore, $batchesAfter)),
            availabilityChanged: $previous->available !== $current->available,
        );
    }

    /**
     * Total weight of every key in an index.
     *
     * @param NodeLogIndex $index Index to read
     *
     * @return array<string, int> Key → total bytes across the live file and every batch
     */
    private static function keyBytes(NodeLogIndex $index): array
    {
        $bytes = [];
        foreach ($index->keys as $key) {
            $bytes[$key->key] = $key->totalBytes;
        }

        return $bytes;
    }

    /**
     * Timestamps of every rotation batch in an index.
     *
     * @param NodeLogIndex $index Index to read
     *
     * @return list<int> Batch Unix timestamps, ascending
     */
    private static function batchTimestamps(NodeLogIndex $index): array
    {
        $timestamps = [];
        foreach ($index->batches as $batch) {
            $timestamps[] = $batch->timestamp;
        }

        return $timestamps;
    }
}
