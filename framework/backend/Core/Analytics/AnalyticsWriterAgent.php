<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosClusterNode;

/**
 * The one writer of analytics tables, wherever policy places it in the cluster (HIL-1155).
 *
 * Its worker receives the node register before start. Every online node is initially waiting;
 * later a ready notice or a changed online register row makes it waiting. It reads one whole file
 * from a waiting node, then visits the next node in id order. Files of one node stay in sequence;
 * there is no order between nodes. An offline or silent node keeps its files on disk while other
 * nodes proceed. A database failure pauses only that node, with its failed file ahead of later
 * files. Loading is blocking, so this agent has a monopolistic worker.
 *
 * See docs/agents/architecture/analytics.md, Across Nodes.
 */
final class AnalyticsWriterAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS_WRITER;

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => AnalyticsJournalPortionSignalData::class,
        HilosSignalConstants::ANALYTICS_JOURNAL_READY => AnalyticsJournalReadySignalData::class,
    ];

    /** @var list<string> The node register is the authority for membership and connectivity. */
    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

    public const int READ_TIMEOUT_MS = 10000;
    public const int RETRY_MIN_MS = 5000;
    public const int RETRY_MAX_MS = 60000;

    private AnalyticsJournalLoader $loader;

    /** @var array<string, true> Nodes that may have ready files */
    private array $waitingNodes = [];

    /** @var array<string, string> Last online and lastSeen value observed for each node */
    private array $seenRows = [];

    /** @var ?string Node with an outstanding read, null when free */
    private ?string $readingNode = null;

    /** @var string File being read, or the oldest-ready sentinel before its first answer */
    private string $file = AnalyticsJournalReadSignalData::OLDEST_READY;

    /** @var list<string> Lines of the current file */
    private array $lines = [];

    /** @var int Offset of the outstanding read */
    private int $offset = 0;

    /** @var int Moment the outstanding read was sent, in milliseconds */
    private int $askedAtMs = 0;

    /** @var ?string Node at which the last file read began, for round-robin selection */
    private ?string $lastStartedNode = null;

    /** @var array<string, AnalyticsWriterNodePause> Database retry state, by node */
    private array $pauses = [];

    /** @var array<string, true> Nodes whose silence has already been logged */
    private array $silentNodes = [];

    /**
     * Opens the loader and marks every online node as worth asking after this start.
     */
    public function onStart(): void
    {
        $this->loader = new AnalyticsJournalLoader(new AnalyticsStore());
        $this->refreshNodes();
    }

    /**
     * Reconciles membership and asks at most one node for a file or a portion.
     *
     * @throws InvalidArgumentException When a read cannot be named
     */
    public function onTick(): void
    {
        $this->step(self::nowMs());
    }

    /**
     * Drives one short scheduling step with a caller-supplied clock.
     *
     * @param int $nowMs Moment of this step, in milliseconds
     * @throws InvalidArgumentException When a read cannot be named
     */
    public function step(int $nowMs): void
    {
        $online = $this->refreshNodes();
        if ($this->readingNode !== null) {
            if (!isset($online[$this->readingNode])) {
                $this->releaseRead();
            } elseif ($nowMs - $this->askedAtMs >= self::READ_TIMEOUT_MS) {
                $node = $this->readingNode;
                $this->releaseRead();
                if (!isset($this->silentNodes[$node])) {
                    $this->silentNodes[$node] = true;
                    $this->logAgentWarning("Analytics writer: node {$node} does not answer, its files wait");
                }
            } else {
                return;
            }
        }

        $nodes = array_keys($this->waitingNodes);
        sort($nodes, SORT_STRING);
        $ordered = $this->lastStartedNode === null
            ? $nodes
            : [...array_filter($nodes, fn(string $node): bool => $node > $this->lastStartedNode),
                ...array_filter($nodes, fn(string $node): bool => $node <= $this->lastStartedNode)];
        foreach ($ordered as $node) {
            if (!isset($online[$node]) || (($this->pauses[$node]->atMs ?? 0) > $nowMs)) {
                continue;
            }

            $this->ask($node, $this->pauses[$node]->file ?? AnalyticsJournalReadSignalData::OLDEST_READY, 0, $nowMs);

            return;
        }
    }

    /**
     * Nothing owned to release: a new writer reads an unfinished file again.
     */
    public function onStop(): void
    {
        // No-op.
    }

    /**
     * Takes the journal's answer or its notice of a ready file.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the signal has no owner here
     * @throws InvalidAgentSignalPayloadException When the payload does not match its declaration
     * @throws InvalidArgumentException When the next read or confirmation cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        $payload = $data->data;
        switch ($name) {
            case HilosSignalConstants::ANALYTICS_JOURNAL_PORTION:
                if (!$payload instanceof AnalyticsJournalPortionSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AnalyticsJournalPortionSignalData::class, $payload);
                }

                $this->applyPortion($payload, self::nowMs());

                return;

            case HilosSignalConstants::ANALYTICS_JOURNAL_READY:
                if (!$payload instanceof AnalyticsJournalReadySignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AnalyticsJournalReadySignalData::class, $payload);
                }

                $this->applyReady($payload, self::nowMs());

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Makes the sender waiting; a restarted journal may have lost its outstanding read.
     *
     * @param AnalyticsJournalReadySignalData $ready Notice from the node
     * @param int $nowMs Moment it arrived, in milliseconds
     * @throws InvalidArgumentException When a repeated read cannot be named
     */
    public function applyReady(AnalyticsJournalReadySignalData $ready, int $nowMs): void
    {
        $node = $ready->nodeId ?? HilosClusterNode::STANDALONE_NODE_ID;
        $this->waitingNodes[$node] = true;
        if ($this->readingNode === $node) {
            $this->ask($node, $this->file, $this->offset, $nowMs);
        }
    }

    /**
     * Accepts only the node, file and offset currently asked for.
     *
     * @param AnalyticsJournalPortionSignalData $portion Portion from a node
     * @param int $nowMs Moment it arrived, in milliseconds
     * @throws InvalidArgumentException When the next read or confirmation cannot be named
     */
    public function applyPortion(AnalyticsJournalPortionSignalData $portion, int $nowMs): void
    {
        $node = $portion->nodeId ?? HilosClusterNode::STANDALONE_NODE_ID;
        if (
            $this->readingNode !== $node
            || $portion->offset !== $this->offset
            || ($this->file !== AnalyticsJournalReadSignalData::OLDEST_READY && $portion->file !== $this->file)
        ) {
            return;
        }

        if (isset($this->silentNodes[$node])) {
            unset($this->silentNodes[$node]);
            $this->logAgentInfo("Analytics writer: node {$node} answers again");
        }

        if ($portion->file === AnalyticsJournalPortionSignalData::NO_READY_FILE) {
            unset($this->waitingNodes[$node]);
            $this->releaseRead();

            return;
        }

        if ($portion->gone) {
            unset($this->pauses[$node]);
            $this->releaseRead();

            return;
        }

        $this->file = $portion->file;
        array_push($this->lines, ...$portion->lines);
        if (!$portion->complete) {
            $this->ask($node, $this->file, $portion->nextOffset, $nowMs);

            return;
        }

        $this->load($node, $nowMs);
    }

    /**
     * Loads one file, or pauses just its node after a database failure.
     *
     * @param string $node Node whose file was read
     * @param int $nowMs Moment of the load, in milliseconds
     * @throws InvalidArgumentException When the confirmation cannot be named
     */
    private function load(string $node, int $nowMs): void
    {
        $file = $this->file;
        $lines = $this->lines;
        $this->releaseRead();

        try {
            $outcome = $this->loader->load($node, $file, $lines);
        } catch (HilosException $failure) {
            $previous = $this->pauses[$node] ?? null;
            if ($previous === null) {
                $this->logAgentError("Analytics writer: node {$node} loading {$file} failed, it is retried later: " . $failure->getMessage());
            }

            $delay = $previous === null ? self::RETRY_MIN_MS : min(self::RETRY_MAX_MS, $previous->delayMs * 2);
            $this->pauses[$node] = new AnalyticsWriterNodePause($file, $nowMs + $delay, $delay);

            return;
        }

        if (isset($this->pauses[$node])) {
            $this->logAgentInfo("Analytics writer: node {$node} takes files again, {$file} loaded");
            unset($this->pauses[$node]);
        }

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
            new AnalyticsJournalLoadedSignalData(nodeId: $node === HilosClusterNode::STANDALONE_NODE_ID ? null : $node, file: $file),
        );
    }

    /**
     * Sends one read to the journal of the named node.
     *
     * @param string $node Node that owns the file
     * @param string $file Ready file, or the oldest-ready sentinel
     * @param int $offset Byte offset to read from
     * @param int $nowMs Moment of the ask, in milliseconds
     * @throws InvalidArgumentException When the read cannot be named
     */
    private function ask(string $node, string $file, int $offset, int $nowMs): void
    {
        $this->readingNode = $node;
        $this->file = $file;
        $this->offset = $offset;
        $this->askedAtMs = $nowMs;
        if ($offset === 0) {
            $this->lastStartedNode = $node;
        }

        $this->sendToAgent(
            HilosSignalConstants::ANALYTICS_JOURNAL_READ,
            new AnalyticsJournalReadSignalData(
                nodeId: $node === HilosClusterNode::STANDALONE_NODE_ID ? null : $node,
                file: $file,
                offset: $offset,
            ),
        );
    }

    /**
     * Updates the waiting set from the node register and returns its online ids.
     *
     * @return array<string, true> Nodes currently reachable from this worker's node
     */
    private function refreshNodes(): array
    {
        $online = [];
        $seen = [];
        foreach (Hilos::$rt?->hilosClusterNodes ?? [] as $row) {
            $node = $row->nodeId;
            $stamp = ($row->online ? '1' : '0') . '|' . $row->lastSeen;
            $seen[$node] = $stamp;
            if ($row->online) {
                $online[$node] = true;
                if (($this->seenRows[$node] ?? null) !== $stamp) {
                    $this->waitingNodes[$node] = true;
                }
            }
        }

        $this->seenRows = $seen;

        return $online;
    }

    private function releaseRead(): void
    {
        $this->readingNode = null;
        $this->file = AnalyticsJournalReadSignalData::OLDEST_READY;
        $this->lines = [];
        $this->offset = 0;
        $this->askedAtMs = 0;
    }

    /**
     * @return int Current Unix time in milliseconds
     */
    private static function nowMs(): int
    {
        return (int)floor(microtime(true) * TimeConstants::MS_PER_SECOND);
    }
}
