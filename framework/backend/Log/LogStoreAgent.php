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
use Hilos\Log\DTO\LogsFollowStartSignalData;
use Hilos\Log\DTO\LogsFollowStopSignalData;
use Hilos\Log\DTO\LogsLinesAppendedSignalData;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use Hilos\Pages\Logs\DTO\LogsFollowStartActionDTO;
use Hilos\Pages\Logs\DTO\LogsFollowStopActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesReplyDTO;
use Hilos\Runtime\ConnectionRosterReconciler;
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
 *
 * Owning the directory is also what makes it the only process that can answer about a file in it:
 * it serves one page of lines on request (HIL-757), and it FOLLOWS one on request (HIL-389) - once
 * a second it reads what each followed file gained and sends it straight to the viewer's socket,
 * which another node may be holding. A follow is dropped when the viewer asks, when its page
 * unsubscribes, and when its connection is no longer on the roster.
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
        HilosSignalConstants::LOGS_AGENT_FOLLOW_START => [
            AgentSignalConfigKey::NODE_FIELD => LogsFollowStartActionDTO::nodeId,
            AgentSignalConfigKey::DTO => LogsFollowStartSignalData::class,
        ],
        HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP => [
            AgentSignalConfigKey::NODE_FIELD => LogsFollowStopActionDTO::nodeId,
            AgentSignalConfigKey::DTO => LogsFollowStopSignalData::class,
        ],
    ];

    /** @var int Lines one page of the viewer holds, the number the mockup shows */
    private const int READ_PAGE_LINES = 200;

    /** @var float Minimum seconds between two live walks of the log root */
    private const float LIVE_SCAN_INTERVAL_SECONDS = 5.0;

    /** @var float Minimum seconds between two full walks, archive included */
    private const float FULL_SCAN_INTERVAL_SECONDS = 60.0;

    /** @var float Minimum seconds between two rounds of reading what the followed files gained */
    private const float FOLLOW_TICK_INTERVAL_SECONDS = 1.0;

    /** @var int Lines one follower is sent per round, the rest waiting for the next one */
    private const int FOLLOW_PUSH_MAX_LINES = 200;

    /** @var int Backlog in bytes past which the owner jumps to the end instead of catching up */
    private const int FOLLOW_CATCHUP_MAX_BYTES = 1048576;

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

    /** @var array<string, LogFollowWatcher> Accept key → what that viewer is following and where it has got to */
    private array $followers = [];

    /** @var float Timestamp of the last round of appended-line reads, for throttling */
    private float $lastFollowPushAt = 0.0;

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
     * Throttle only: it decides WHICH work is due and does none of it itself.
     *
     * Two independent clocks, because the two jobs answer different questions at different rates:
     * the walk says what files this node has, the follow says what one of them just gained. The
     * follow runs after the walk and never instead of it, so a minute-old rotation cannot be
     * reported by one half and denied by the other.
     *
     * @throws InvalidArgumentException When a frame to a following viewer cannot be named
     */
    public function onTick(): void
    {
        $now = microtime(true);
        $this->walkWhicheverIsDue($now);
        if ($this->followers === [] || $now - $this->lastFollowPushAt < self::FOLLOW_TICK_INTERVAL_SECONDS) {
            return;
        }
        $this->lastFollowPushAt = $now;

        $this->pushAppendedLines();
    }

    /**
     * Runs the live or the full walk when its interval has come round, and neither otherwise.
     *
     * @param float $now Monotonic-enough wall clock of this tick
     */
    private function walkWhicheverIsDue(float $now): void
    {
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
     * Answers one read of one file of this node's log directory, or takes a follow of one on or off.
     *
     * The blocking file walk is legitimate here and nowhere else: this agent is monopolistic and
     * node-local (HIL-753), so one reader of one directory is exactly what it is for.
     *
     * @param AgentSignalData $data Signal data (container with inner payload)
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws AgentUnknownSignalException When the agent is reached by a signal it does not own
     * @throws InvalidAgentSignalPayloadException When a frame carries the wrong payload
     * @throws InvalidArgumentException When the answer to the read or the follow cannot be named
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

            case HilosSignalConstants::LOGS_AGENT_FOLLOW_START:
                $start = $data->data;
                if (!$start instanceof LogsFollowStartSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, LogsFollowStartSignalData::class, $start);
                }

                $this->handleFollowStart($start);

                return;

            case HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP:
                $stop = $data->data;
                if (!$stop instanceof LogsFollowStopSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, LogsFollowStopSignalData::class, $stop);
                }

                unset($this->followers[$stop->acceptKey]);

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
     * Starts following one live file, answering with the page that ends where the follow begins.
     *
     * The size is taken BEFORE the first read and is the position the follow continues from. Taken
     * after, it would skip whatever the writer appended while the page was being read; reading
     * from the end of the file and then taking it would send those lines twice.
     *
     * A file that is not there is not a refusal and does not cancel the follow: a worker may not
     * have started yet, and its log will appear under the name the viewer already chose. The first
     * page simply says it could not be read, and the follow picks the file up when it exists.
     *
     * @param LogsFollowStartSignalData $request Follow request, carrying whom to answer
     * @throws InvalidArgumentException When the success or failure ack cannot be named
     */
    private function handleFollowStart(LogsFollowStartSignalData $request): void
    {
        try {
            $reader = LogLineReader::fromEnv();
            $size = $reader->size($request->stream);
            $page = $reader->read($request->stream, new LogReadQuery(
                LogReadQuery::ANCHOR_TAIL,
                cursor: $size,
                limit: self::READ_PAGE_LINES,
                levelFilter: $request->level,
                substring: $request->substring,
            ));

            $this->followers[$request->acceptKey] = new LogFollowWatcher(
                acceptKey: $request->acceptKey,
                requestId: $request->requestId,
                stream: $request->stream,
                level: $request->level,
                substring: $request->substring,
                offset: $size ?? 0,
            );
            $this->sendActionSuccess(
                $request->acceptKey,
                $request->action,
                $request->requestId,
                LogsReadLinesReplyDTO::fromPage($page),
            );
        } catch (Throwable $e) {
            unset($this->followers[$request->acceptKey]);
            $this->logAgentError('Log follow failed to start: ' . $e->getMessage());
            $this->sendActionFail($request->acceptKey, $request->action, $request->requestId, $e->getMessage());
        }
    }

    /**
     * Sends every following viewer what its file gained since the last round.
     *
     * Public and unthrottled for the reason the two walks are ({@see walkStore()}): the round is
     * the agent's work and {@see onTick()} is only its clock, so what a round reads does not depend
     * on when it was scheduled. That clock runs once a second, because every read here is blocking
     * file I/O in the one worker that also walks this directory, and a log tail does not need
     * milliseconds.
     *
     * Viewers are read one by one even when they share a file: each has its own position and its
     * own filters, and folding them into a single pass would be a scheduler built for a screen
     * that a handful of administrators look at.
     *
     * @throws InvalidArgumentException When a frame to a following viewer cannot be named
     */
    public function pushAppendedLines(): void
    {
        $reader = LogLineReader::fromEnv();
        foreach ($this->followers as $acceptKey => $watcher) {
            if (!$this->viewerStillConnected($acceptKey)) {
                unset($this->followers[$acceptKey]);

                continue;
            }

            try {
                $this->pushToViewer($reader, $watcher);
            } catch (Throwable $e) {
                unset($this->followers[$acceptKey]);
                $this->logAgentError("Log follow of '{$watcher->stream}' failed: " . $e->getMessage());
                $this->sendToUser(
                    HilosSignalConstants::LOGS_LINES_APPENDED,
                    $acceptKey,
                    LogsLinesAppendedSignalData::stopped($watcher->requestId),
                );
            }
        }
    }

    /**
     * Whether the socket a follow answers to is still on the node's connection roster.
     *
     * Asked before the file is touched, deliberately: reading for somebody who has gone is exactly
     * the work this leaf promised not to do. It is also the only thing that catches a viewer who
     * left without a word - a tab that closed cleanly is released by the page's own unsubscribe,
     * and a socket struck out by {@see ConnectionRosterReconciler::reconcile()} is caught here.
     *
     * A project with no connections collection at all cannot answer, and a follow nobody can ever
     * verify would live as long as the process; it is treated as gone.
     *
     * @param string $acceptKey Accept key of the following connection
     * @return bool True when the roster still carries that connection
     */
    private function viewerStillConnected(string $acceptKey): bool
    {
        $connections = Hilos::$rt?->connectionsSource();

        return $connections?->get($acceptKey) !== null;
    }

    /**
     * Reads one viewer's file forward and sends the one thing that happened to it, if anything did.
     *
     * The four outcomes are exclusive and each is a whole frame. A file smaller than the position
     * read from was carried off by rotation or truncated, so the follow restarts at the beginning
     * of the file now under that name and says so - a missing file counts as size zero, which is
     * why a follow of a file that never appears sends nothing rather than a rotation a second. A
     * file that has not grown says nothing at all: silence under a level filter is the right
     * answer. A backlog past {@see self::FOLLOW_CATCHUP_MAX_BYTES} is jumped over rather than
     * shipped, because a tail showing the day before yesterday is lying about the word "now", and
     * a queue of unshown lines grows faster than a reader drains it.
     *
     * @param LogLineReader $reader Reader bound to this node's log root
     * @param LogFollowWatcher $watcher Viewer to serve, whose position this advances
     * @throws InvalidArgumentException When the frame to the viewer cannot be named
     */
    private function pushToViewer(LogLineReader $reader, LogFollowWatcher $watcher): void
    {
        $size = $reader->size($watcher->stream) ?? 0;
        if ($size < $watcher->offset()) {
            $watcher->jumpTo(0);
            $this->sendFrame($watcher, LogsLinesAppendedSignalData::rotated($watcher->requestId));

            return;
        }
        if ($size === $watcher->offset()) {
            return;
        }

        $behind = $size - $watcher->offset();
        if ($behind > self::FOLLOW_CATCHUP_MAX_BYTES) {
            $watcher->jumpTo($size);
            $this->sendFrame($watcher, LogsLinesAppendedSignalData::skipped($watcher->requestId, $behind));

            return;
        }

        $page = $reader->read($watcher->stream, new LogReadQuery(
            LogReadQuery::ANCHOR_HEAD,
            cursor: $watcher->offset(),
            limit: self::FOLLOW_PUSH_MAX_LINES,
            levelFilter: $watcher->level,
            substring: $watcher->substring,
            inheritedLevel: $watcher->inheritedLevel(),
        ));
        $watcher->advanceTo($page->endCursor, $page->endLevel);
        if ($page->lines === []) {
            return;
        }

        $this->sendFrame($watcher, LogsLinesAppendedSignalData::appended($watcher->requestId, $page));
    }

    /**
     * Sends one frame to the socket a follow answers to.
     *
     * Straight to the connection rather than back through the page: the page has nothing to add
     * to it, and the browser may be attached to a different node altogether, which the router
     * already knows how to reach.
     *
     * @param LogFollowWatcher $watcher Viewer the frame is for
     * @param LogsLinesAppendedSignalData $frame What happened to the file
     * @throws InvalidArgumentException When the frame cannot be named
     */
    private function sendFrame(LogFollowWatcher $watcher, LogsLinesAppendedSignalData $frame): void
    {
        $this->sendToUser(HilosSignalConstants::LOGS_LINES_APPENDED, $watcher->acceptKey, $frame);
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
