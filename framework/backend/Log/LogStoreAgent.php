<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\FsException;
use Hilos\Hilos;
use Hilos\Log\DTO\LogsFollowStartSignalData;
use Hilos\Log\DTO\LogsFollowStopSignalData;
use Hilos\Log\DTO\LogsLinesAppendedSignalData;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Log\DTO\LogsTakeoutConfirmSignalData;
use Hilos\Log\DTO\LogsTakeoutUndoSignalData;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use Hilos\Pages\Logs\DTO\LogsFollowStartActionDTO;
use Hilos\Pages\Logs\DTO\LogsFollowStopActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesReplyDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmActionDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmReplyDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutUndoActionDTO;
use Hilos\Runtime\ConnectionRosterReconciler;
use Hilos\Utils\Exception\LogRotationException;
use JsonException;
use Throwable;

/**
 * Node-local monopolistic agent owning the log directory (HIL-753).
 *
 * A concrete framework agent, the way {@see LogAggregatorAgent} is: owning a directory of files
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
 *
 * And it is the only process that can WRITE in that directory, which is what the takeout
 * confirmation rests on (HIL-483): an operator who has carried a rotation batch off says so, and
 * the fact is a marker file inside the batch ({@see LogBatchTakeoutMarker}) rather than a row
 * anywhere else. The index carries it back out, so the screen that asked draws what the disk
 * actually holds. The same word can be taken back while the batch is still here
 * (HIL-759): the marker is removed, the row returns to the verdict the retention rule reads for
 * it, and the only refusal is a batch the pruner has already carried away.
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
        HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM => [
            AgentSignalConfigKey::NODE_FIELD => LogsTakeoutConfirmActionDTO::nodeId,
            AgentSignalConfigKey::DTO => LogsTakeoutConfirmSignalData::class,
        ],
        HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO => [
            AgentSignalConfigKey::NODE_FIELD => LogsTakeoutUndoActionDTO::nodeId,
            AgentSignalConfigKey::DTO => LogsTakeoutUndoSignalData::class,
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

    /** @var float Seconds after which the index is reported even though nothing about it changed */
    private const float KEEPALIVE_INTERVAL_SECONDS = 60.0;

    /** @var int Milliseconds between two frames when no administrator has written the setting */
    private const int DEFAULT_PUSH_INTERVAL_MS = 5000;

    /** @var int Smallest interval a written setting is obeyed at, in milliseconds */
    private const int MIN_PUSH_INTERVAL_MS = 100;

    /** @var int Bytes in one mebibyte, the unit the raw-output complaint is worded in */
    private const int BYTES_PER_MEBIBYTE = 1024 * 1024;

    /**
     * @var int Size past which a daemon raw stream is worth a line. A constant and not a setting:
     *     in a healthy node that file is empty, so any size at all is already an anomaly rather
     *     than a matter of taste.
     */
    private const int RAW_STREAM_COMPLAINT_BYTES = 16 * self::BYTES_PER_MEBIBYTE;

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

    /** @var float Timestamp of the last index frame sent to the aggregator, for throttling */
    private float $lastPushAt = 0.0;

    /**
     * @var bool Whether a walk has found anything to report since the last frame went out. It
     *     ACCUMULATES rather than being read off the last walk: frames are rarer than walks, so
     *     the walk that happens to be the latest when a frame is due is often the one that found
     *     nothing, while an earlier one did.
     */
    private bool $indexChangedSincePush = false;

    private LogSettingsResolver $resolver;

    /** @var ?LogRotator Rotator for the running node, or null when the env cannot name the log directory */
    private ?LogRotator $rotator = null;

    /** @var LogRotationTriggerPolicy Trigger axes as the settings last answered them */
    private LogRotationTriggerPolicy $policy;

    /** @var ?CronRule Schedule axis; null when no valid expression is configured */
    private ?CronRule $cronRule = null;

    /** @var ?string Expression the current schedule axis was built from, so it is rebuilt only on a change */
    private ?string $cronExpression = null;

    /** @var float Timestamp of the last rotation ATTEMPT, the age-axis baseline */
    private float $lastRotationAt = 0.0;

    /**
     * @var bool Last verdict of the device gate, so only a crossing is reported. Seeded open the
     *     way the index is seeded readable: a start is not a crossing, and a live directory whose
     *     archive sits somewhere else is news.
     */
    private bool $sameDeviceVerdict = true;

    /** @var array<string, true> Raw stream basename → its size has already been complained about */
    private array $rawStreamComplained = [];

    /**
     * Builds the reader and the rotator, learns which node this is, and takes the first full walk
     * as the baseline.
     *
     * @throws EnvException When the cluster-enabled flag or a cluster environment value cannot be read
     * @throws ClusterConfigurationException When cluster mode is on but the local node config is missing or invalid
     * @throws InvalidArgumentException When the first frame to the aggregator cannot be named
     */
    public function onStart(): void
    {
        $this->reader = LogStoreReader::fromEnv();
        try {
            $this->rotator = LogRotator::forRuntime();
        } catch (EnvException) {
            // The same degrade the reader makes one line above: an environment that cannot name the
            // log directory leaves the owner running and reporting an unavailable store, with
            // nothing to read and nothing to rotate — rather than leaving the node without an owner.
            $this->rotator = null;
        }
        $this->resolver = new LogSettingsResolver();
        $this->refreshPolicy();
        // The daemon rotated the live logs on its way up a moment ago, so the age axis counts from
        // now; anything else would fire once on every start of every node.
        $this->lastRotationAt = microtime(true);
        $cluster = Hilos::$cluster;
        $clustered = $cluster !== null && $cluster->isEnabled();
        $this->nodeId = $clustered ? $cluster->identity()->nodeId : null;
        // Seeded as readable so the baseline walk stays silent on a healthy store and still says
        // one line on a broken one: a start is not a crossing, an unreadable directory is news.
        $this->index = new NodeLogIndex(
            $this->nodeId,
            true,
            time(),
            [],
            [],
            [],
            [],
            $this->reader->logDirectory(),
            $this->resolver->takeoutUndoWindowSeconds(),
        );
        $this->snapshot = LogStoreSnapshot::unavailable();
        $this->lastFullScanAt = microtime(true);
        $this->lastLiveScanAt = $this->lastFullScanAt;
        $this->walkStore(time());
        // A start is a rotation too — the daemon rotated the live logs on its way up — so it is a
        // moment to clean up after, and the walk above is what tells this pass which batches there
        // are to consider.
        $this->pruneTakenBatches();
        // Reported at once rather than at the first due moment: a node that comes up after the
        // aggregator would otherwise be missing from the cluster picture for a whole interval, and
        // nothing about that absence would say it is only the schedule.
        $this->pushIndex();
    }

    /**
     * Throttle only: it decides WHICH work is due and does none of it itself.
     *
     * Three independent clocks, because the three jobs answer different questions at different
     * rates: the walk says what files this node has, the follow says what one of them just gained,
     * and the report tells the cluster aggregator both. The follow runs after the walk and never
     * instead of it, so a minute-old rotation cannot be reported by one half and denied by the
     * other; the report runs last of all, on what the walk of this same tick has just published.
     *
     * The rotation trigger has no clock of its own: it rides the walk that just measured the live
     * files, so the size axis reads a weight already in hand instead of globbing the directory a
     * second time (HIL-480).
     *
     * @throws InvalidArgumentException When a frame to a following viewer or to the aggregator cannot be named
     * @throws DatabaseException When the written push interval cannot be read
     */
    public function onTick(): void
    {
        $now = microtime(true);
        if ($this->walkWhicheverIsDue($now)) {
            $this->rotateIfDue($now);
        }
        if ($this->followers !== [] && $now - $this->lastFollowPushAt >= self::FOLLOW_TICK_INTERVAL_SECONDS) {
            $this->lastFollowPushAt = $now;
            $this->pushAppendedLines();
        }

        $this->pushIndexIfDue($now);
    }

    /**
     * Runs the live or the full walk when its interval has come round, and neither otherwise.
     *
     * @param float $now Monotonic-enough wall clock of this tick
     * @return bool True when a walk ran, which is what the rotation trigger hangs on
     */
    private function walkWhicheverIsDue(float $now): bool
    {
        if ($now - $this->lastFullScanAt >= self::FULL_SCAN_INTERVAL_SECONDS) {
            $this->lastFullScanAt = $now;
            $this->lastLiveScanAt = $now;
            $this->walkStore((int)$now);

            return true;
        }
        if ($now - $this->lastLiveScanAt < self::LIVE_SCAN_INTERVAL_SECONDS) {
            return false;
        }
        $this->lastLiveScanAt = $now;

        $this->walkLiveFiles((int)$now);

        return true;
    }

    /**
     * Rotates the live logs when an axis of the policy calls for it, and says so.
     *
     * The trigger moved here from a worker agent of its own (HIL-480): the owner of the directory
     * already walks it every few seconds, so the size axis costs nothing here, and the moment a
     * batch appears becomes known exactly instead of being inferred from a live key that shrank.
     *
     * Every attempt resets the age baseline, including one that moved nothing: an empty directory
     * would otherwise stay past its age forever and call for a rotation on every walk.
     *
     * The clock is a parameter rather than a reading, the way the walks take theirs: this method
     * is the whole of the trigger, and the tick is only what schedules it.
     *
     * The cleanup of carried-off batches (HIL-382) rides this same moment, between the rotation and
     * the walk that follows it, so one frame carries both halves of what just changed in the
     * archive: the batch that appeared, and the ones that are gone.
     *
     * @param float $now Monotonic-enough wall clock of the walk this check rides
     * @throws InvalidArgumentException When the frame announcing the new batch cannot be named
     */
    public function rotateIfDue(float $now): void
    {
        // An index nobody can read is no ground to move files on: the store is unreachable or
        // unconfigured, and a rename decided from a stale picture is a guess.
        $rotator = $this->rotator;
        if ($rotator === null || !$this->index->available) {
            return;
        }

        $this->refreshPolicy();
        if (!$this->policy->isActive() || !$this->shouldRotate($now, $rotator)) {
            return;
        }
        if (!$this->deviceGateOpen($rotator)) {
            return;
        }

        $this->lastRotationAt = $now;
        $report = $this->rotateOnce($rotator);
        // Hung on the ATTEMPT and not on its outcome: a node quiet enough to have nothing to move
        // makes no batch, and a cleanup waiting for one would never run there at all.
        $this->pruneTakenBatches();
        if ($report?->batchDirName === null) {
            return;
        }

        $this->logAgentInfo("Log rotation: moved {$report->movedCount} live log file(s)");
        // Out of turn, both of them: a batch appeared this instant, and the screen would otherwise
        // learn of it a full walk and a push interval later.
        $this->lastFullScanAt = $now;
        $this->lastLiveScanAt = $now;
        $this->walkStore((int)$now);
        $this->pushIndex();
    }

    /**
     * Runs one rotation and reports what it could not move, best-effort throughout.
     *
     * The failure answers with nothing rather than with an empty report: a rotation that could not
     * make its batch is not the same event as one that found nothing to move, and only the caller's
     * next step — announcing a batch — treats the two alike.
     *
     * @param LogRotator $rotator Rotator bound to this node's log directory
     * @return ?LogRotationReport What the rotation did, or null when it could not run at all
     */
    private function rotateOnce(LogRotator $rotator): ?LogRotationReport
    {
        try {
            $report = $rotator->rotate();
        } catch (LogRotationException $exception) {
            // Best-effort: a failed rotation must never crash the owner; the next walk tries again.
            $this->logAgentError('Log rotation failed: ' . $exception->getMessage());

            return null;
        }

        foreach ($report->failedFiles as $failedFile) {
            $this->logAgentError("Log rotation could not move {$failedFile}");
        }

        return $report;
    }

    /**
     * Removes the batches an operator has confirmed carrying off, and says what happened (HIL-382).
     *
     * Unlike the backup pruner next door this one is never silent about what it removed: a backup
     * can be taken again, a log cannot, and a batch that disappeared without a line in the journal
     * would be a deletion nobody could account for afterwards. A pass that found nothing to do says
     * nothing at all, which on a live node is most of them.
     *
     * The pruner is handed every batch of the current index and decides for itself, re-reading each
     * marker off the disk: a confirmation may have been withdrawn since the walk, and this walk's
     * picture of the archive can be a minute old.
     */
    private function pruneTakenBatches(): void
    {
        $logDirectory = $this->reader->logDirectory();
        if ($logDirectory === null || !$this->index->available) {
            return;
        }

        $report = (new LogArchivePruner($logDirectory))->prune(self::batchTimestamps($this->index));

        foreach ($report->removedBatchTimestamps as $batchTimestamp => $takenAt) {
            $batch = self::batchLabel(date(LogRotationConstants::TIMESTAMP_FORMAT, $batchTimestamp));
            $this->logAgentInfo("Log cleanup: removed batch {$batch}, taken at " . date('Y-m-d H:i:s', $takenAt));
        }
        foreach ($report->failedPaths as $failedPath) {
            $this->logAgentError("Log cleanup could not remove {$failedPath}");
        }
        foreach ($report->keptDirNames as $dirName) {
            $batch = self::batchLabel($dirName);
            $this->logAgentError("Log cleanup left batch {$batch} in place: it holds files that are not logs");
        }
        foreach ($report->unreadableMarkerDirNames as $dirName) {
            $batch = self::batchLabel($dirName);
            $this->logAgentWarning(
                "Log cleanup cannot read the takeout marker of batch {$batch}, so the batch counts as not taken",
            );
        }
    }

    /**
     * Names one batch the way the journal names it: relative to the log root, and as a directory.
     *
     * @param string $dirName Name of the batch directory
     * @return string The batch as a line of the journal spells it
     */
    private static function batchLabel(string $dirName): string
    {
        return LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME . '/' . $dirName . '/';
    }

    /**
     * Re-reads the policy from the settings and re-arms the schedule axis when it changed.
     *
     * Whatever the resolver had to complain about goes to the journal here, and it only speaks when
     * the outcome changed, so a value that stays wrong does not fill the log it configures.
     *
     * The policy is rebuilt on every check (HIL-760) so an edited threshold is obeyed within
     * seconds. The schedule is the one exception: its {@see CronRule} remembers when it last ran,
     * so it is rebuilt only when the expression itself changes — rebuilding it every check would
     * restart that memory and the schedule would never fire.
     */
    private function refreshPolicy(): void
    {
        $this->policy = $this->resolver->rotationPolicy();

        while (($complaint = $this->resolver->takeComplaint()) !== null) {
            $this->logAgentError($complaint);
        }

        // Compared by expression and not by "is there a rule": an expression that yields no rule is
        // refused once, and re-deciding it every check would report the same refusal every check.
        $expression = $this->policy->cronExpression;
        if ($expression === $this->cronExpression) {
            return;
        }

        $this->cronExpression = $expression;
        $this->cronRule = $this->policy->createCronRule();
        if ($this->cronRule === null && $expression !== null && trim($expression) !== '') {
            $this->logAgentError("Log rotation: ignoring invalid cron expression '{$expression}'");
        }
    }

    /**
     * Evaluates the axes in cheapest-first order.
     *
     * @param float $now Current wall clock, the age-axis reference
     * @param LogRotator $rotator Rotator whose kept names the size axis leaves out
     * @return bool True when any axis calls for a rotation now
     */
    private function shouldRotate(float $now, LogRotator $rotator): bool
    {
        if ($this->cronRule?->shouldRun() ?? false) {
            return true;
        }
        if ($this->policy->ageExceeded($now - $this->lastRotationAt)) {
            return true;
        }

        return $this->policy->sizeExceeded($this->rotatableLiveBytes($rotator));
    }

    /**
     * Weight of the live files rotation could actually move.
     *
     * The daemon's raw streams are left out, and not merely because they stay behind: counted in,
     * a raw file grown past the threshold would hold it exceeded for good, and every walk would
     * call for a rotation that cannot bring the number down.
     *
     * The figure comes from the walk that just ran rather than from a directory listing of its
     * own — that is the whole reason this check rides the walk.
     *
     * @param LogRotator $rotator Rotator naming what it would leave behind
     * @return int Summed size in bytes of the live files rotation would move
     */
    private function rotatableLiveBytes(LogRotator $rotator): int
    {
        $keptBasenames = $rotator->keptBasenames();

        $total = 0;
        foreach ($this->liveKeyBytes as $name => $bytes) {
            if (in_array($name, $keptBasenames, true)) {
                continue;
            }
            $total += $bytes;
        }

        return $total;
    }

    /**
     * Whether rotation may run, saying one line whenever the answer changes.
     *
     * @param LogRotator $rotator Rotator naming the live and archive directories
     * @return bool True when the archive is on the device of the live logs
     */
    private function deviceGateOpen(LogRotator $rotator): bool
    {
        $open = $this->archiveOnSameDevice($rotator);
        if ($open === $this->sameDeviceVerdict) {
            return $open;
        }

        $this->sameDeviceVerdict = $open;
        if ($open) {
            $this->logAgentInfo('Log rotation is on again: the archive directory is back on the device of the live logs');
        } else {
            $this->logAgentError(
                'Log rotation is off: the archive directory is on a different device than the live logs, '
                . 'so a batch could only be moved by copying every byte',
            );
        }

        return $open;
    }

    /**
     * Whether the archive directory sits on the device holding the live logs.
     *
     * Asked before every rotation and not once at start: a mount point can be put under a running
     * node. Across a device boundary a rename is no longer a rename — the kernel refuses it, and
     * doing it anyway would mean copying every byte, which is the cost this whole design exists to
     * avoid. An archive that does not exist yet passes: rotation creates it inside the live
     * directory, which is the same device by construction.
     *
     * A directory neither of them can be measured leaves the gate OPEN rather than shut: the
     * rename that follows reports its own failure, and refusing on a reading that never arrived
     * would stop rotation for a reason nobody could name.
     *
     * Kept a method of its own on purpose: two devices cannot be arranged inside a unit test, so
     * the refusing half is exercised by hand on a stand with a bind mount over `archive/`.
     *
     * @param LogRotator $rotator Rotator naming the live and archive directories
     * @return bool True when a batch can be made by renaming
     */
    private function archiveOnSameDevice(LogRotator $rotator): bool
    {
        $archiveDirectory = $rotator->archiveDirectory();
        if (!is_dir($archiveDirectory)) {
            return true;
        }

        // warning-suppressed: an unstattable directory leaves the gate open, see the docblock
        $live = @stat($rotator->logDirectory());
        // warning-suppressed: same degrade as the line above
        $archive = @stat($archiveDirectory);
        if ($live === false || $archive === false) {
            return true;
        }

        return $live['dev'] === $archive['dev'];
    }

    /**
     * Says one line about a daemon raw stream that has grown, and nothing while it stays grown.
     *
     * That file is the one thing runtime rotation may not touch, so it is also the one that can
     * grow without bound. In a healthy node it is empty and this never speaks; a node printing
     * warnings past the Logger between restarts gets told once, and hears nothing more until the
     * restart that replaces the file brings it back under the threshold.
     */
    private function complainAboutRawStreams(): void
    {
        if ($this->rotator === null) {
            return;
        }

        foreach ($this->rotator->keptBasenames() as $basename) {
            $bytes = $this->liveKeyBytes[$basename] ?? 0;
            if ($bytes < self::RAW_STREAM_COMPLAINT_BYTES) {
                unset($this->rawStreamComplained[$basename]);

                continue;
            }
            if (isset($this->rawStreamComplained[$basename])) {
                continue;
            }

            $this->rawStreamComplained[$basename] = true;
            $mebibytes = intdiv($bytes, self::BYTES_PER_MEBIBYTE);
            $this->logAgentWarning(
                "The daemon raw output {$basename} has grown to {$mebibytes} MiB "
                . 'and is only rotated when the daemon restarts',
            );
        }
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
     * Answers one read of one file of this node's log directory, takes a follow of one on or off,
     * or records that one rotation batch has been carried off.
     *
     * The blocking file walk is legitimate here and nowhere else: this agent is monopolistic and
     * node-local (HIL-753), so one reader of one directory is exactly what it is for.
     *
     * @param AgentSignalData $data Signal data (container with inner payload)
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws AgentUnknownSignalException When the agent is reached by a signal it does not own
     * @throws InvalidAgentSignalPayloadException When a frame carries the wrong payload
     * @throws InvalidArgumentException When the answer to the read, the follow, the confirmation
     *     or its withdrawal cannot be named
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

            case HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM:
                $confirm = $data->data;
                if (!$confirm instanceof LogsTakeoutConfirmSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, LogsTakeoutConfirmSignalData::class, $confirm);
                }

                $this->handleTakeoutConfirm($confirm);

                return;

            case HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO:
                $undo = $data->data;
                if (!$undo instanceof LogsTakeoutUndoSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, LogsTakeoutUndoSignalData::class, $undo);
                }

                $this->handleTakeoutUndo($undo);

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
        $this->complainAboutRawStreams();
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
            $this->sendActionFailure($request->acceptKey, $request->action, $request->requestId, $e, detailAllowed: false);
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
            $this->sendActionFailure($request->acceptKey, $request->action, $request->requestId, $e, detailAllowed: false);
        }
    }

    /**
     * Records that one batch has been carried off, and answers the browser waiting on it.
     *
     * The whole of it is guarded for the reason the read is: this agent is the LAST step of
     * somebody else's action ({@see AbstractHilosLogsRotationsPage} deferred its ack when it
     * handed the request over), so a failure that only reached the log would leave a modal
     * spinning on another machine until the browser gave up on itself.
     *
     * @param LogsTakeoutConfirmSignalData $request Confirmation request, carrying whom to answer
     * @throws InvalidArgumentException When the success or failure ack cannot be named
     */
    private function handleTakeoutConfirm(LogsTakeoutConfirmSignalData $request): void
    {
        if ($request->requestId === null) {
            // Nothing to answer: an untracked confirmation has no ack to correlate, and writing a
            // durable fact for a reply nobody receives would leave the asker unable to see it.
            $this->logAgentWarning('Ignoring an untracked takeout confirmation: no request id to answer on');

            return;
        }

        try {
            $reply = new LogsTakeoutConfirmReplyDTO(
                $this->confirmTakeout($request->batchTimestamp, $request->userId),
            );
            // The screen does not answer for this by itself: the badge repaints when this
            // node's next index reaches the mirror, which is a moment later and somewhere
            // else on the page. The sentence is what tells the person their click landed.
            $this->setActionSuccessMessage('The batch is recorded as carried off.');
            $this->sendActionSuccess($request->acceptKey, $request->action, $request->requestId, $reply);
        } catch (Throwable $e) {
            $this->logAgentError('Log takeout confirmation failed: ' . $e->getMessage());
            $this->sendActionFailure($request->acceptKey, $request->action, $request->requestId, $e, detailAllowed: false);
        }
    }

    /**
     * Writes the marker of one batch, having re-judged the batch it is asked about.
     *
     * The three questions are asked in the order that keeps the promise the screen makes. Is the
     * directory still there — a batch cleaned away between the click and the frame is gone, and
     * saying so is the honest answer. Is it already confirmed — then this IS the answer, with the
     * stamp that is on disk, so a second tab and a second administrator are told what the first
     * was told rather than an error about a fact they were right about. Only then, is the policy
     * still recommending it — because a batch that came back under protection while the modal was
     * open must not be confirmed, and that guard is about what has NOT been confirmed yet.
     *
     * The stamp is this node's clock and not the browser's: the fact belongs to the directory, and
     * the machine holding it is the one whose time its neighbours can compare against.
     *
     * The walk and the frame that follow are out of turn on purpose. Both would come round on
     * their own within a minute, and for the length of that minute the person who clicked would be
     * looking at the row they just changed, unchanged.
     *
     * @param int $batchTimestamp Unix timestamp of the batch to confirm
     * @param ?int $userId Id of the user who confirmed, or null when the connection carries no user
     * @return int Unix timestamp the batch is recorded as carried off at
     * @throws ValidationException When the batch is gone, is protected again, or its directory
     *     cannot be written to — the three refusals whose own text reaches the person who asked
     * @throws InvalidArgumentException When the frame announcing the confirmation cannot be named
     */
    private function confirmTakeout(int $batchTimestamp, ?int $userId): int
    {
        $directory = $this->batchDirectory($batchTimestamp);
        if ($directory === null || !is_dir($directory)) {
            throw new ValidationException('This batch is no longer on the node');
        }

        $existing = LogBatchTakeoutMarker::read($directory);
        if ($existing !== null) {
            return $existing;
        }

        if (!$this->batchIsDue($batchTimestamp)) {
            throw new ValidationException('The batch is protected again');
        }

        $takenAt = time();
        try {
            LogBatchTakeoutMarker::write($directory, $takenAt, $userId);
        } catch (FsException | JsonException $failure) {
            throw new ValidationException('The batch directory cannot be written to', 0, $failure);
        }

        $this->logAgentInfo("Log takeout confirmed for batch {$batchTimestamp}");
        $now = microtime(true);
        $this->lastFullScanAt = $now;
        $this->lastLiveScanAt = $now;
        $this->walkStore((int)$now);
        $this->pushIndex();

        return $takenAt;
    }

    /**
     * Takes one batch's takeout confirmation back, and answers the browser waiting on it.
     *
     * Guarded whole for the reason the confirmation is: this agent is the LAST step of somebody
     * else's action ({@see AbstractHilosLogsRotationsPage} deferred its ack when it handed the
     * request over), so a failure that only reached the log would leave a modal spinning on
     * another machine until the browser gave up on itself.
     *
     * @param LogsTakeoutUndoSignalData $request Withdrawal request, carrying whom to answer
     * @throws InvalidArgumentException When the success or failure ack cannot be named
     */
    private function handleTakeoutUndo(LogsTakeoutUndoSignalData $request): void
    {
        if ($request->requestId === null) {
            // Nothing to answer: an untracked withdrawal has no ack to correlate, and taking a
            // durable fact away for a reply nobody receives would leave the asker unable to see it.
            $this->logAgentWarning('Ignoring an untracked takeout withdrawal: no request id to answer on');

            return;
        }

        try {
            $this->undoTakeout($request->batchTimestamp);
            // The screen does not answer for this by itself either: the badge goes back to its
            // policy verdict when this node's next index reaches the mirror, a moment later and
            // somewhere else on the page. The sentence is what tells the person their click landed.
            $this->setActionSuccessMessage('Acknowledgement withdrawn — the batch is waiting to be carried off again.');
            $this->sendActionSuccess($request->acceptKey, $request->action, $request->requestId);
        } catch (Throwable $e) {
            $this->logAgentError('Log takeout withdrawal failed: ' . $e->getMessage());
            $this->sendActionFailure($request->acceptKey, $request->action, $request->requestId, $e, detailAllowed: false);
        }
    }

    /**
     * Removes the marker of one batch, having asked the one question that can refuse it.
     *
     * Only one, and it is about the batch rather than about the rule: a confirmation covers the
     * policy verdict over, so withdrawing it can uncover `due` or `kept` and neither is a reason
     * to refuse — the invariant "only a recommended batch may be confirmed" guards against
     * confirming too much, and withdrawing creates nothing to guard. What can honestly refuse is
     * a batch that is no longer here: the pruner passed between the click and this frame, and
     * there is nothing left to put back on the list.
     *
     * A batch that carries no marker is answered as a success with the same sentence. Two
     * administrators clicking in turn both meant the state this leaves behind, and telling the
     * second one off for a fact they were right about would be an error about nothing.
     *
     * The walk and the frame that follow are out of turn for the reason the confirmation's are:
     * both would come round on their own within a minute, and for that minute the person who
     * clicked would be looking at the row they just changed, unchanged.
     *
     * @param int $batchTimestamp Unix timestamp of the batch to withdraw the confirmation of
     * @throws ValidationException When the batch is gone, or its marker cannot be removed — the
     *     two refusals whose own text reaches the person who asked
     * @throws InvalidArgumentException When the frame announcing the withdrawal cannot be named
     */
    private function undoTakeout(int $batchTimestamp): void
    {
        $directory = $this->batchDirectory($batchTimestamp);
        if ($directory === null || !is_dir($directory)) {
            throw new ValidationException('The batch is no longer on this node.');
        }

        if (LogBatchTakeoutMarker::read($directory) === null) {
            return;
        }

        try {
            LogBatchTakeoutMarker::remove($directory);
        } catch (FsException $failure) {
            throw new ValidationException('The batch directory cannot be written.', 0, $failure);
        }

        $this->logAgentInfo("Log takeout withdrawn for batch {$batchTimestamp}");
        $now = microtime(true);
        $this->lastFullScanAt = $now;
        $this->lastLiveScanAt = $now;
        $this->walkStore((int)$now);
        $this->pushIndex();
    }

    /**
     * Whether the retention rule recommends carrying this batch off as things stand now.
     *
     * The rule is re-read rather than remembered, through the same {@see self::judgeDueBatches()}
     * the walk publishes its verdict with: the whole point of the check is that an administrator
     * may have raised the retention period since the modal was opened, so it judges by the clock
     * of the click and not by the list the last walk left in the index.
     *
     * @param int $batchTimestamp Unix timestamp of the batch in question
     * @return bool True when the policy names this batch among the ones to carry off
     */
    private function batchIsDue(int $batchTimestamp): bool
    {
        return in_array($batchTimestamp, $this->judgeDueBatches(self::batchTimestamps($this->index), time()), true);
    }

    /**
     * Applies the retention rule to this node's archive: the one place the policy is read (HIL-871).
     *
     * The node owns the files, so the node is the only judge of them, and both callers reach the
     * rule through here - the pass that publishes the verdict for the screen, and the guard that
     * refuses a confirmation for a batch the rule protects. Being the only judge means one place
     * in the CODE and not one value over time: the guard deliberately re-judges with a fresh clock
     * and a fresh resolver, because the threshold may have been raised while the modal was open.
     *
     * It is asked over THIS node's archive and never over the cluster's, which is the reason the
     * verdict can only be reached here at all: {@see LogArchiveRetentionPolicy::$keepBatches}
     * means "the newest N of THIS directory", so one list across the cluster would spend the whole
     * protection on N batches in total and recommend carrying off a neighbour's freshest batch.
     *
     * The resolver is read on every pass, the way rotation reads it on every check
     * ({@see self::refreshPolicy()}): an edited threshold is then honoured without a restart, and
     * a policy cache of our own would be one more thing to invalidate for no gain.
     *
     * @param list<int> $batchTimestamps Timestamps of the batches in this node's archive
     * @param int $now Instant every batch's age is measured against, in Unix seconds
     * @return list<int> The subset the rule recommends carrying off, in the order it was given them
     */
    private function judgeDueBatches(array $batchTimestamps, int $now): array
    {
        return $this->resolver->retentionPolicy()->selectEvictionCandidates($batchTimestamps, $now);
    }

    /**
     * Names the archive directory of one batch on this node.
     *
     * The second place the wire's unix stamp meets the directory name rotation writes, and it
     * formats it the way {@see self::relativePath()} does — in the timezone of the process that
     * wrote that name, which for this node is this very process.
     *
     * @param int $batchTimestamp Unix timestamp of the batch
     * @return ?string Absolute directory path, or null when the environment names no log root
     */
    private function batchDirectory(int $batchTimestamp): ?string
    {
        $logDirectory = $this->reader->logDirectory();
        if ($logDirectory === null) {
            return null;
        }

        return $logDirectory . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME
            . DIRECTORY_SEPARATOR . date(LogRotationConstants::TIMESTAMP_FORMAT, $batchTimestamp);
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
     * The retention verdict is reached HERE, on every pass, full walk and live one alike, and it
     * is measured by the clock of the walk rather than by the clock of this call (HIL-871): the
     * index says what was true when the store was read, not when the reading was written down.
     * Passing it through {@see self::diff()} afterwards needs no help - a verdict that moved is an
     * axis of the delta like any other.
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

        $batches = $snapshot->batches();
        $batchTimestamps = array_map(static fn (LogBatchSummary $batch): int => $batch->timestamp, $batches);

        $previous = $this->index;
        $this->index = new NodeLogIndex(
            nodeId: $this->nodeId,
            available: $snapshot->available,
            sampledAt: $sampledAt,
            batches: $batches,
            keys: $keys,
            workers: $snapshot->workers(),
            growthBytesPerDay: $growth,
            logDirectory: $this->reader->logDirectory(),
            takeoutUndoWindowSeconds: $this->resolver->takeoutUndoWindowSeconds(),
            dueBatchTimestamps: $this->judgeDueBatches($batchTimestamps, $sampledAt),
        );
        $this->lastDelta = self::diff($previous, $this->index);
        // Raised here and not where the frame is scheduled, because this is the one moment the
        // question can be answered: by the time a frame is due the last walk has usually found
        // nothing, and asking it then would deny a change an earlier walk did find.
        if (!$this->lastDelta->isEmpty()) {
            $this->indexChangedSincePush = true;
        }
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
        foreach ($delta->confirmedBatchTimestamps as $timestamp) {
            $this->logAgentDebug("Log store: batch {$timestamp} confirmed as carried off");
        }
        foreach ($delta->withdrawnBatchTimestamps as $timestamp) {
            $this->logAgentDebug("Log store: batch {$timestamp} is no longer confirmed as carried off");
        }
    }

    /**
     * Sends this node's index to the cluster aggregator when it is time to, and nothing otherwise.
     *
     * Two ways a frame becomes due, and the cheap one is asked first. Ordinarily something has
     * changed and the interval since the last frame has run out. Failing that, one frame a minute
     * goes out with nothing new to say - not as a sign of life, which cluster membership answers,
     * but so that an aggregator restarted or moved by policy rebuilds its picture of a quiet
     * system instead of waiting for the next thing to happen in its logs. A minute is the full
     * walk's own period: nothing this node could report changes faster than it is measured.
     *
     * The written interval is read only when a change is waiting on it, which keeps the settings
     * lookup off the ticks of a node with nothing to say.
     *
     * The clock is a parameter rather than a reading, the way the walks take theirs: the tick is
     * only this method's throttle, and when a frame is due should not depend on when the loop got
     * round to asking.
     *
     * @param float $now Monotonic-enough wall clock of this tick
     * @throws InvalidArgumentException When the frame cannot be named
     * @throws DatabaseException When the written push interval cannot be read
     */
    public function pushIndexIfDue(float $now): void
    {
        $sinceLastFrame = $now - $this->lastPushAt;
        if ($sinceLastFrame >= self::KEEPALIVE_INTERVAL_SECONDS) {
            $this->pushIndex();

            return;
        }
        if (!$this->indexChangedSincePush) {
            return;
        }
        if ($sinceLastFrame * TimeConstants::MS_PER_SECOND < $this->resolvePushIntervalMs()) {
            return;
        }

        $this->pushIndex();
    }

    /**
     * Sends the index as it stands and forgets what it had to report.
     *
     * Nothing is waited for: {@see AbstractAgent::sendToAgent()} queues the frame and returns, no
     * acknowledgement comes back, and no retry of this node's own is built. The frame is the whole
     * index, so a lost one costs nothing that the next does not repair - and while the aggregator
     * is unplaced or moving there is no address at all and the signal is dropped, which is the
     * same case seen from the other end.
     *
     * @throws InvalidArgumentException When the frame cannot be named
     */
    private function pushIndex(): void
    {
        $this->sendToAgent(
            HilosSignalConstants::LOGS_NODE_INDEX_REPORT,
            NodeLogIndexSignalData::fromIndex($this->index),
        );
        // DEBUG, like the rest of what this agent says about its own routine: it writes into the
        // directory it measures, and a line per frame would be the biggest thing in the index.
        $this->logAgentDebug(
            'Log store: index reported, ' . count($this->index->keys) . ' key(s), '
            . count($this->index->batches) . ' batch(es)',
        );
        $this->indexChangedSincePush = false;
        $this->lastPushAt = microtime(true);
    }

    /**
     * How long this node waits between two frames, in milliseconds.
     *
     * The WRITTEN setting and nothing beneath it. The catalog default under this key resolves out
     * of the node's own environment, so walking the full ladder would let three nodes of one
     * cluster report at three different rates with nothing on any screen to explain why; the
     * literal below is the same on every node, which is the property that matters more than the
     * number. A value under the floor is clamped rather than obeyed - the same floor the rule on
     * the setting refuses a write below, applied again here because a row can be older than the
     * rule or written past it.
     *
     * Asked at the moment the next frame is planned, so an administrator's edit is obeyed within
     * one round and not at the next restart of the node.
     *
     * @return int Milliseconds between two frames
     * @throws DatabaseException When the settings lookup fails
     */
    private function resolvePushIntervalMs(): int
    {
        if (!Hilos::$db instanceof HilosDbContext) {
            return self::DEFAULT_PUSH_INTERVAL_MS;
        }

        /**
         * @noinspection PhpPossiblePolymorphicInvocationInspection Framework-level magic settings
         *     property on abstract HilosDbContext; runtime instance is always concrete
         */
        $written = Hilos::$db->settings[LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS]?->value;
        if ($written === null || !is_numeric($written)) {
            return self::DEFAULT_PUSH_INTERVAL_MS;
        }

        return max(self::MIN_PUSH_INTERVAL_MS, (int)$written);
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
     * Difference between two indexes: what appeared, grew, vanished, was confirmed, changed its
     * retention verdict, and whether the store changed side.
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
            confirmedBatchTimestamps: self::newlyConfirmed($previous, $current),
            withdrawnBatchTimestamps: self::newlyWithdrawn($previous, $current),
            verdictChangedBatchTimestamps: self::verdictChanged($previous, $current),
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
     * Batches that gained a takeout confirmation between two indexes.
     *
     * Its own axis and not part of the appeared/vanished pair, because a confirmation is the one
     * change that moves NOTHING else: the same batches, the same files, the same weights, and one
     * marker the walk does not weigh (HIL-483). Without this line the frame carrying an operator's
     * own click would be judged empty and never sent.
     *
     * A stamp that merely changed is not counted: the marker is written once and never rewritten
     * here, so a different stamp on the same batch is a directory rebuilt underneath us rather
     * than news to report.
     *
     * @param NodeLogIndex $previous Older index
     * @param NodeLogIndex $current Newer index
     *
     * @return list<int> Timestamps of batches confirmed in the newer index and not in the older
     */
    private static function newlyConfirmed(NodeLogIndex $previous, NodeLogIndex $current): array
    {
        $before = [];
        foreach ($previous->batches as $batch) {
            if ($batch->takenAt !== null) {
                $before[$batch->timestamp] = true;
            }
        }

        $confirmed = [];
        foreach ($current->batches as $batch) {
            if ($batch->takenAt !== null && !isset($before[$batch->timestamp])) {
                $confirmed[] = $batch->timestamp;
            }
        }

        return $confirmed;
    }

    /**
     * Batches that lost a takeout confirmation between two indexes (HIL-759).
     *
     * The mirror image of {@see self::newlyConfirmed()} and a list of its own, because the two are
     * opposite news about the same field: folded together they would say a batch had been
     * confirmed at the very moment its confirmation went away. Without this line the frame
     * carrying an operator's withdrawal would be judged empty and never sent — the marker is the
     * only thing between the two walks that moved, and the walk does not weigh it.
     *
     * A batch that vanished along with its marker is not counted: it is already reported as
     * vanished, and naming it here as well would ask the screen to put a row back that has gone.
     *
     * @param NodeLogIndex $previous Older index
     * @param NodeLogIndex $current Newer index
     *
     * @return list<int> Timestamps of batches confirmed in the older index and present but unconfirmed in the newer
     */
    private static function newlyWithdrawn(NodeLogIndex $previous, NodeLogIndex $current): array
    {
        $before = [];
        foreach ($previous->batches as $batch) {
            if ($batch->takenAt !== null) {
                $before[$batch->timestamp] = true;
            }
        }

        $withdrawn = [];
        foreach ($current->batches as $batch) {
            if ($batch->takenAt === null && isset($before[$batch->timestamp])) {
                $withdrawn[] = $batch->timestamp;
            }
        }

        return $withdrawn;
    }

    /**
     * Batches whose retention verdict moved between two indexes, either way (HIL-871).
     *
     * Symmetric on purpose, where {@see self::newlyConfirmed()} and {@see self::newlyWithdrawn()}
     * are a pair of one-way lists. A confirmation and its withdrawal are opposite news about an
     * operator's own act and read differently on the screen; a verdict is one field of one batch,
     * and both of its crossings are the same news - this batch is drawn with another badge now.
     * The reverse crossing is real and not theoretical: an administrator raising `keep_batches`
     * pulls a batch back under protection, and a frame that skipped it would leave the screen
     * offering to carry off what the node has already gone back to refusing.
     *
     * A batch that arrived or vanished along with its verdict is not counted: those crossings are
     * already reported on their own axes, and naming a gone batch here would ask the screen to
     * repaint a row that is no longer there.
     *
     * @param NodeLogIndex $previous Older index
     * @param NodeLogIndex $current Newer index
     *
     * @return list<int> Timestamps present in both indexes and named due by exactly one of them
     */
    private static function verdictChanged(NodeLogIndex $previous, NodeLogIndex $current): array
    {
        $before = array_flip($previous->dueBatchTimestamps);
        $after = array_flip($current->dueBatchTimestamps);

        $present = [];
        foreach ($previous->batches as $batch) {
            $present[$batch->timestamp] = true;
        }

        $changed = [];
        foreach ($current->batches as $batch) {
            if (!isset($present[$batch->timestamp])) {
                continue;
            }
            if (isset($before[$batch->timestamp]) !== isset($after[$batch->timestamp])) {
                $changed[] = $batch->timestamp;
            }
        }

        return $changed;
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
