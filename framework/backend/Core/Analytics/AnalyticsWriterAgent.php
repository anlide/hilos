<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosClusterNode;

/**
 * The one writer of the analytics tables in a cluster (HIL-1154).
 *
 * Placed by policy, one instance for the cluster, on a monopolistic worker: a load is blocking
 * database work, and it runs right in the handler of the portion that completes a file. It takes
 * the ready files of its own node through that node's journal agent ({@see AnalyticsJournalAgent})
 * - the files of other nodes wait on their disks until HIL-1155 - strictly in their order:
 *
 * - once a second, while it holds no file, it asks for the oldest ready one;
 * - it reads the file portion by portion, asking again when an answer is ten seconds late;
 * - a whole file is one transaction ({@see AnalyticsJournalLoader}), then it confirms the file,
 *   and the journal agent deletes it; a file loaded before is only confirmed again;
 * - a database failure throws what was read away and asks for the same file again after five
 *   seconds, doubling up to a minute, so no later file of the node lands before it.
 *
 * It starts with empty caches: the numbers it learns live only in its own memory and are kept
 * only once their transaction committed.
 */
final class AnalyticsWriterAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS_WRITER;

    /** The one frame it takes: a portion of a ready file. */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => AnalyticsJournalPortionSignalData::class,
    ];

    public const int POLL_INTERVAL_MS = 1000;
    public const int READ_TIMEOUT_MS = 10000;
    public const int RETRY_MIN_MS = 5000;
    public const int RETRY_MAX_MS = 60000;

    private AnalyticsJournalLoader $loader;

    /** @var ?string Cluster node id of this node, null off a cluster */
    private ?string $nodeId = null;

    /** @var string Ready file being read, {@see AnalyticsJournalReadSignalData::OLDEST_READY} while none is */
    private string $file = AnalyticsJournalReadSignalData::OLDEST_READY;

    /** @var list<string> Lines of that file read so far */
    private array $lines = [];

    /** @var int Offset of the portion asked for */
    private int $offset = 0;

    /** @var bool Whether a read is outstanding */
    private bool $waiting = false;

    /** @var int Moment the outstanding read was sent, in milliseconds */
    private int $askedAtMs = 0;

    /** @var int Moment of the last ask for the oldest ready file, in milliseconds */
    private int $lastPollAtMs = 0;

    /** @var string File a failed load is to be retried with, {@see AnalyticsJournalReadSignalData::OLDEST_READY} when nothing failed */
    private string $retryFile = AnalyticsJournalReadSignalData::OLDEST_READY;

    /** @var int Moment the retry may start, in milliseconds */
    private int $retryAtMs = 0;

    /** @var int Pause before the next retry, 0 while loads succeed */
    private int $retryDelayMs = 0;

    /**
     * Learns which node this is and opens an empty store.
     *
     * @throws EnvException When the cluster flag or a cluster value cannot be read
     * @throws ClusterConfigurationException When cluster mode is on but the local node config is missing or invalid
     */
    public function onStart(): void
    {
        $cluster = Hilos::$cluster;
        $this->nodeId = $cluster !== null && $cluster->isEnabled() ? $cluster->identity()->nodeId : null;
        $this->loader = new AnalyticsJournalLoader(new AnalyticsStore());
    }

    /**
     * Asks for the next file when free, again when an answer is late, and the failed file once its pause is over.
     *
     * @throws InvalidArgumentException When a read cannot be named
     */
    public function onTick(): void
    {
        $this->pollIfDue(self::nowMs());
    }

    /**
     * Throttle only: decides which read is due and sends it.
     *
     * @param int $nowMs Moment of this tick, in milliseconds
     * @throws InvalidArgumentException When a read cannot be named
     */
    public function pollIfDue(int $nowMs): void
    {
        if ($this->waiting) {
            if ($nowMs - $this->askedAtMs >= self::READ_TIMEOUT_MS) {
                $this->ask($this->file, $this->offset, $nowMs);
            }

            return;
        }

        if ($nowMs < $this->retryAtMs || $nowMs - $this->lastPollAtMs < self::POLL_INTERVAL_MS) {
            return;
        }

        $this->lastPollAtMs = $nowMs;
        $this->lines = [];
        $this->ask($this->retryFile, 0, $nowMs);
    }

    /**
     * Nothing owned to release: what was read and not loaded is read again by the next writer.
     */
    public function onStop(): void
    {
        // No-op.
    }

    /**
     * Takes a portion of the file it asked for; the portion that completes a file loads it.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the agent is reached by a signal it does not own
     * @throws InvalidAgentSignalPayloadException When the payload is not the class the signal declares
     * @throws InvalidArgumentException When the next read or the confirmation cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        if ($name !== HilosSignalConstants::ANALYTICS_JOURNAL_PORTION) {
            throw new AgentUnknownSignalException($name);
        }

        $portion = $data->data;
        if (!$portion instanceof AnalyticsJournalPortionSignalData) {
            throw new InvalidAgentSignalPayloadException($name, AnalyticsJournalPortionSignalData::class, $portion);
        }

        $this->applyPortion($portion, self::nowMs());
    }

    /**
     * Files one portion: the next read, the load of a complete file, or the end of a file that is gone.
     *
     * A portion that is not the one asked for - a late answer to a read sent again - is dropped.
     *
     * @param AnalyticsJournalPortionSignalData $portion The portion
     * @param int $nowMs Moment it arrived, in milliseconds
     * @throws InvalidArgumentException When the next read or the confirmation cannot be named
     */
    public function applyPortion(AnalyticsJournalPortionSignalData $portion, int $nowMs): void
    {
        $expected = $this->waiting
            && $portion->offset === $this->offset
            && ($this->file === AnalyticsJournalReadSignalData::OLDEST_READY || $portion->file === $this->file);
        if (!$expected) {
            return;
        }

        $this->waiting = false;
        if ($portion->file === AnalyticsJournalPortionSignalData::NO_READY_FILE || $portion->gone) {
            // No ready file, or the one asked for is gone - deleted after an earlier confirmation,
            // or thrown away under a freeze: nothing to load, the writer is free again.
            $this->file = AnalyticsJournalReadSignalData::OLDEST_READY;
            $this->lines = [];
            $this->retryFile = AnalyticsJournalReadSignalData::OLDEST_READY;

            return;
        }

        $this->file = $portion->file;
        array_push($this->lines, ...$portion->lines);
        if (!$portion->complete) {
            $this->ask($this->file, $portion->nextOffset, $nowMs);

            return;
        }

        $this->load($nowMs);
    }

    /**
     * Loads the file read whole and confirms it, or schedules the same file again after a database failure.
     *
     * @param int $nowMs Moment of the load, in milliseconds
     * @throws InvalidArgumentException When the confirmation cannot be named
     */
    private function load(int $nowMs): void
    {
        $file = $this->file;
        $lines = $this->lines;
        $this->file = AnalyticsJournalReadSignalData::OLDEST_READY;
        $this->lines = [];

        try {
            $outcome = $this->loader->load($this->nodeId ?? HilosClusterNode::STANDALONE_NODE_ID, $file, $lines);
        } catch (HilosException $failure) {
            if ($this->retryDelayMs === 0) {
                $this->logAgentError("Analytics writer: loading {$file} failed, it is retried later: " . $failure->getMessage());
            }

            $this->retryDelayMs = $this->retryDelayMs === 0
                ? self::RETRY_MIN_MS
                : min(self::RETRY_MAX_MS, $this->retryDelayMs * 2);
            $this->retryAtMs = $nowMs + $this->retryDelayMs;
            $this->retryFile = $file;

            return;
        }

        if ($this->retryDelayMs !== 0) {
            $this->logAgentInfo("Analytics writer: the database takes files again, {$file} loaded");
        }

        $this->retryDelayMs = 0;
        $this->retryAtMs = 0;
        $this->retryFile = AnalyticsJournalReadSignalData::OLDEST_READY;
        // The next file is asked for at once: a backlog drains at the pace of the database, not of the poll.
        $this->lastPollAtMs = 0;

        if ($outcome->skippedCount() > 0) {
            $reasons = [];
            foreach ($outcome->skipped as $reason => $count) {
                $reasons[] = "{$reason} {$count}";
            }

            $this->logAgentWarning(
                "Analytics writer: {$file} loaded with {$outcome->skippedCount()} of {$outcome->recordCount} record(s) passed over: "
                . implode(', ', $reasons),
            );
        }

        $this->sendToAgent(
            HilosSignalConstants::ANALYTICS_JOURNAL_LOADED,
            new AnalyticsJournalLoadedSignalData(nodeId: $this->nodeId, file: $file),
        );
    }

    /**
     * Asks the journal agent of this node for a portion.
     *
     * @param string $file Ready file to read, {@see AnalyticsJournalReadSignalData::OLDEST_READY} for the oldest one
     * @param int $offset Byte offset to read from
     * @param int $nowMs Moment of the ask, in milliseconds
     * @throws InvalidArgumentException When the read cannot be named
     */
    private function ask(string $file, int $offset, int $nowMs): void
    {
        $this->file = $file;
        $this->offset = $offset;
        $this->waiting = true;
        $this->askedAtMs = $nowMs;

        $this->sendToAgent(
            HilosSignalConstants::ANALYTICS_JOURNAL_READ,
            new AnalyticsJournalReadSignalData(nodeId: $this->nodeId, file: $file, offset: $offset),
        );
    }

    /**
     * @return int Current Unix time in milliseconds
     */
    private static function nowMs(): int
    {
        return (int)floor(microtime(true) * TimeConstants::MS_PER_SECOND);
    }
}
