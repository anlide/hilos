<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\FsException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosClusterNode;

/**
 * The journal agent of a node: the one owner of the node's analytics journal (HIL-1154).
 *
 * Registered per node ({@see AgentScope::NODE}) on a monopolistic worker: it writes files, which is
 * blocking work, and one process per node owns the subdirectory, so nothing is locked. Every
 * process of the node but the master hands it its events in batches
 * ({@see HilosSignalConstants::ANALYTICS_JOURNAL_APPEND}); it appends them to the open file and
 * leaves the rest to {@see AnalyticsJournalDirectory}: the sync once a second, the rotation, the
 * names. The writer reads ready files through it and confirms each one it loaded, which deletes
 * the file. Each rotation and a start that finds ready files also tells the writer that this node
 * has work; the writer's own register scan covers a notice lost during its move or restart.
 *
 * What came in is guaranteed to reach the database now or later - with the losses the owner
 * accepted and docs/agents/architecture/analytics.md lists: a machine crash costs up to a second,
 * a crash of this process costs the frames already in its socket, and a freeze costs the journal.
 * A batch that cannot be written - the disk, its rights - is lost, said once per change of outcome,
 * and the next batch tries again.
 *
 * The subdirectory is `<analytics_journal>/<APP_ENV>/<node>`: one environment cannot see another's
 * files where several mount the same data directory, and neither can a node another's.
 */
final class AnalyticsJournalAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS_JOURNAL;

    /**
     * A batch always goes to the sender's own node; the read and the confirmation name the node in
     * the payload, so a writer elsewhere reaches this one. This agent sends ready notices to the
     * one cluster writer; it does not take them (HIL-1155).
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::ANALYTICS_JOURNAL_APPEND => AnalyticsJournalAppendSignalData::class,
        HilosSignalConstants::ANALYTICS_JOURNAL_READ => [
            AgentSignalConfigKey::NODE_FIELD => AnalyticsJournalReadSignalData::nodeId,
            AgentSignalConfigKey::DTO => AnalyticsJournalReadSignalData::class,
        ],
        HilosSignalConstants::ANALYTICS_JOURNAL_LOADED => [
            AgentSignalConfigKey::NODE_FIELD => AnalyticsJournalLoadedSignalData::nodeId,
            AgentSignalConfigKey::DTO => AnalyticsJournalLoadedSignalData::class,
        ],
    ];

    /** @var string Subdirectory name of the node off a cluster, where there is no node id */
    private const string STANDALONE_NODE = 'node';

    private const string LINE_BREAK = "\n";

    private const string OPERATION_START = 'set up its directory';
    private const string OPERATION_WRITE = 'write';
    private const string OPERATION_SYNC = 'sync or rotate';
    private const string OPERATION_READ = 'read';
    private const string OPERATION_DELETE = 'delete a loaded file';

    /** @var ?AnalyticsJournalDirectory The journal, null until the start resolved its subdirectory */
    private ?AnalyticsJournalDirectory $journal = null;

    /** @var bool Whether the subdirectory was set up; a failed start is tried again by the next batch or tick */
    private bool $started = false;

    /** @var ?string Cluster node id of this node, null off a cluster */
    private ?string $nodeId = null;

    /** @var array<string, true> Operations whose failure was said and has not cleared since */
    private array $failingOperations = [];

    /**
     * Learns which node this is, resolves the subdirectory, and closes what a previous life left open.
     *
     * @throws EnvException When the cluster flag, a cluster value or APP_ENV cannot be read
     * @throws ClusterConfigurationException When cluster mode is on but the local node config is missing or invalid
     * @throws DirectoryNotFoundException When the project registers no analytics_journal directory
     * @throws InvalidArgumentException When a ready notice cannot be named
     */
    public function onStart(): void
    {
        $cluster = Hilos::$cluster;
        if (Hilos::$fs === null) {
            throw new DirectoryNotFoundException('FS directory [' . FsContext::ANALYTICS_JOURNAL . '] has no FS context to live in');
        }

        $nodeId = $cluster !== null && $cluster->isEnabled() ? $cluster->identity()->nodeId : null;
        $root = rtrim(Hilos::$fs->getDirectory(FsContext::ANALYTICS_JOURNAL)->getPath(), '/');
        $this->openJournal(
            new AnalyticsJournalDirectory(
                $root . '/' . Hilos::$env[EnvConstants::APP_ENV]->string() . '/' . ($nodeId ?? self::STANDALONE_NODE),
                $nodeId ?? HilosClusterNode::STANDALONE_NODE_ID,
            ),
            $nodeId,
        );
    }

    /**
     * Takes the journal of this node and sets its subdirectory up.
     *
     * {@see self::onStart()} resolves the subdirectory and comes here; a test hands a journal of its own.
     *
     * @param AnalyticsJournalDirectory $journal The node's journal
     * @param ?string $nodeId Cluster node id of this node, null off a cluster
     * @throws InvalidArgumentException When a ready notice cannot be named
     */
    public function openJournal(AnalyticsJournalDirectory $journal, ?string $nodeId): void
    {
        $this->journal = $journal;
        $this->nodeId = $nodeId;
        $this->started = false;
        $this->startJournal();
    }

    /**
     * Syncs and rotates the open file when due; a start that failed is tried again here.
     *
     * @throws InvalidArgumentException When a ready notice cannot be named
     */
    public function onTick(): void
    {
        if (!$this->ensureStarted()) {
            return;
        }

        try {
            if ($this->journal?->tick(self::nowMs()) !== null) {
                $this->announceReady();
            }
            $this->clearFailure(self::OPERATION_SYNC);
        } catch (FsException $failure) {
            $this->reportFailure(self::OPERATION_SYNC, $failure);
        }
    }

    /**
     * Closes the open file as ready; under a freeze throws the whole journal away instead.
     *
     * The freeze comes before a restore, and the restored database would not know the journal
     * belongs to it: what was not loaded by then is lost, by the owner's decision, and counted by
     * HIL-1157. Asked here and not at the swap, because the swap is invisible to an agent - the
     * re-read round comes on a failed restore too.
     *
     * @throws InvalidArgumentException When a ready notice cannot be named
     */
    public function onStop(): void
    {
        if ($this->journal === null || !$this->ensureStarted(false)) {
            return;
        }

        try {
            if (Hilos::$rt?->hilosProtectedModeRuntime?->silencesUnstoppedWriters() === true) {
                $discarded = $this->journal->discardAll();
                $this->logAgentInfo("Analytics journal: the freeze stopped it, {$discarded} file(s) of this node thrown away");

                return;
            }

            $this->journal->rotate(self::nowMs());
        } catch (FsException $failure) {
            $this->logAgentError('Analytics journal: could not close the journal on the way out: ' . $failure->getMessage());
        }
    }

    /**
     * Takes a batch, answers a read, deletes a loaded file.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the agent is reached by a signal it does not own
     * @throws InvalidAgentSignalPayloadException When the payload is not the class the signal declares
     * @throws InvalidArgumentException When the answer to a read cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        $payload = $data->data;
        switch ($name) {
            case HilosSignalConstants::ANALYTICS_JOURNAL_APPEND:
                if (!$payload instanceof AnalyticsJournalAppendSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AnalyticsJournalAppendSignalData::class, $payload);
                }

                $this->append($payload->lines);

                return;

            case HilosSignalConstants::ANALYTICS_JOURNAL_READ:
                if (!$payload instanceof AnalyticsJournalReadSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AnalyticsJournalReadSignalData::class, $payload);
                }

                $this->answerRead($payload);

                return;

            case HilosSignalConstants::ANALYTICS_JOURNAL_LOADED:
                if (!$payload instanceof AnalyticsJournalLoadedSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AnalyticsJournalLoadedSignalData::class, $payload);
                }

                $this->deleteLoaded($payload->file);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Appends the lines of a batch; an empty line or one holding a line break is dropped, since it would break the file.
     *
     * @param list<string> $lines Lines of the batch
     */
    private function append(array $lines): void
    {
        $kept = array_values(array_filter(
            $lines,
            static fn(string $line): bool => $line !== '' && !str_contains($line, self::LINE_BREAK),
        ));
        if ($kept === [] || !$this->ensureStarted()) {
            return;
        }

        try {
            if ($this->journal?->append($kept, self::nowMs()) !== null) {
                $this->announceReady();
            }
            $this->clearFailure(self::OPERATION_WRITE);
        } catch (FsException $failure) {
            $this->reportFailure(self::OPERATION_WRITE, $failure);
        }
    }

    /**
     * Answers the writer with the portion it asked for, or with the oldest ready file's first portion.
     *
     * A failure to read is said and answered with nothing: the writer asks again after its timeout.
     *
     * @param AnalyticsJournalReadSignalData $request What the writer asked
     * @throws InvalidArgumentException When the answer cannot be named
     */
    private function answerRead(AnalyticsJournalReadSignalData $request): void
    {
        if ($this->journal === null || !$this->ensureStarted()) {
            return;
        }

        try {
            $file = $request->file === AnalyticsJournalReadSignalData::OLDEST_READY
                ? $this->journal->oldestReady()
                : $request->file;
            $portion = $file === null ? null : $this->journal->readPortion($file, $request->offset);
            $this->clearFailure(self::OPERATION_READ);
        } catch (FsException $failure) {
            $this->reportFailure(self::OPERATION_READ, $failure);

            return;
        }

        if ($portion !== null && $portion->passedOver > 0) {
            $this->logAgentWarning(
                "Analytics journal: passed over {$portion->passedOver} line(s) longer than "
                . AnalyticsJournalRecord::MAX_LINE_BYTES . " bytes in {$file}",
            );
        }

        $this->sendToAgent(HilosSignalConstants::ANALYTICS_JOURNAL_PORTION, new AnalyticsJournalPortionSignalData(
            nodeId: $this->nodeId,
            file: $file ?? AnalyticsJournalPortionSignalData::NO_READY_FILE,
            offset: $request->offset,
            nextOffset: $portion?->nextOffset ?? $request->offset,
            lines: $portion?->lines ?? [],
            complete: $portion?->complete ?? false,
            gone: $file !== null && $portion === null,
        ));
    }

    /**
     * Deletes a ready file the writer has in the database; a second confirmation finds nothing to delete.
     *
     * @param string $file Name of the ready file
     */
    private function deleteLoaded(string $file): void
    {
        if ($this->journal === null || !$this->ensureStarted()) {
            return;
        }

        try {
            $this->journal->delete($file);
            $this->clearFailure(self::OPERATION_DELETE);
        } catch (FsException $failure) {
            $this->reportFailure(self::OPERATION_DELETE, $failure);
        }
    }

    /**
     * Sets up the subdirectory unless that is done.
     *
     * @param bool $announceReady Whether a recovered ready file should notify the writer
     * @return bool Whether the journal is ready to work
     */
    private function ensureStarted(bool $announceReady = true): bool
    {
        if (!$this->started) {
            $this->startJournal($announceReady);
        }

        return $this->started;
    }

    /**
     * Sets up the subdirectory and closes what a previous life left open.
     *
     * @param bool $announceReady Whether a recovered ready file should notify the writer
     */
    private function startJournal(bool $announceReady = true): void
    {
        if ($this->journal === null) {
            return;
        }

        try {
            $closed = $this->journal->start();
            // The listing belongs to the start: if it fails, the next tick must retry and
            // announce files that would otherwise remain unseen by an already running writer.
            $hasReady = $announceReady && $this->journal->oldestReady() !== null;
            $this->started = true;
            $this->clearFailure(self::OPERATION_START);
            if ($closed > 0) {
                $this->logAgentInfo("Analytics journal: closed {$closed} file(s) a previous life left open");
            }
            if ($hasReady) {
                $this->announceReady();
            }
        } catch (FsException $failure) {
            $this->reportFailure(self::OPERATION_START, $failure);
        }
    }

    /**
     * Tells the cluster writer that this node has at least one ready file.
     *
     * @throws InvalidArgumentException When the notice cannot be named
     */
    private function announceReady(): void
    {
        $this->sendToAgent(
            HilosSignalConstants::ANALYTICS_JOURNAL_READY,
            new AnalyticsJournalReadySignalData($this->nodeId),
        );
    }

    /**
     * Says a failure once per change of outcome, not on every batch.
     *
     * @param string $operation What failed, one of the OPERATION_* words
     * @param FsException $failure The failure
     */
    private function reportFailure(string $operation, FsException $failure): void
    {
        if (isset($this->failingOperations[$operation])) {
            return;
        }

        $this->failingOperations[$operation] = true;
        $this->logAgentError("Analytics journal cannot {$operation}, what it handles meanwhile is lost: " . $failure->getMessage());
    }

    /**
     * Forgets the failure of an operation that worked again, silently, so one that returns is said again.
     *
     * @param string $operation What worked, one of the OPERATION_* words
     */
    private function clearFailure(string $operation): void
    {
        unset($this->failingOperations[$operation]);
    }

    /**
     * @return int Current Unix time in milliseconds
     */
    private static function nowMs(): int
    {
        return (int)floor(microtime(true) * TimeConstants::MS_PER_SECOND);
    }
}
