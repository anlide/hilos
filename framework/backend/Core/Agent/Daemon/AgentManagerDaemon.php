<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentDaemonNotRegisteredException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PageAccessReassessConnectionsSignalData;
use Hilos\Core\Page\DTO\PageAccessReassessUserSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Source\Interest\SourceReaderMap;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Database\DTO\ReHydrateVerdict;
use Hilos\Database\ReHydrateBarrierSink;
use Hilos\Database\ReHydrateRound;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\ProtectedMode\ProtectedModeAgentStopSink;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\TruthSource\RtNodeSourceMap;
use Hilos\TruthSource\RtReplicaOriginMap;
use Hilos\Utils\Logger;

/**
 * AgentManagerDaemon - Base class for managing agent daemons in daemon process.
 *
 * Manages lifecycle of agent daemons running in daemon process.
 * Provides unified interface for agent daemon creation, storage and retrieval.
 * Uses workerId mapping instead of WorkerClient objects (workerId: negative = monopolistic, positive = regular).
 * Child classes must implement createAgentDaemon() factory method.
 */
abstract class AgentManagerDaemon implements ReHydrateBarrierSink
{
    /** @var array<string, AgentDaemonInterface> Active agent daemons indexed by agent ID */
    protected array $agentDaemons = [];

    /** @var array<string, int> Mapping agentId => workerId (negative = monopolistic, positive = regular) */
    protected array $agentToWorker = [];

    /** @var array<string, true> Ids of agents that reported agent_started, so their onStart has completed */
    private array $startedAgentIds = [];

    /**
     * @var ?ReHydrateRound Barrier of the re-hydrate announcement in flight, null when none is.
     *
     * It lives here rather than in {@see DaemonManager} because this object is the one the
     * daemon loop and every inbound {@see WorkerClient} already share: the announcement arrives
     * on one worker's link, the answers arrive on all of them, and the verdict is addressed to
     * an agent - which is this class's subject.
     */
    private ?ReHydrateRound $reHydrateRound = null;

    /** @var ?string Agent that announced the swap on this node; null when another node announced it */
    private ?string $reHydrateInitiator = null;

    /** @var ?string Node that announced the swap to this one; null when this node announced it itself */
    private ?string $reHydrateReplyToNodeId = null;

    /**
     * @var ?RtNodeSourceMap What RT collections this node owns, built lazily on first use.
     *
     * Here for the same reason the barrier above is: the reports arrive on the workers' links
     * and the answer is read by the daemon loop, and this object is what those two share. It is
     * keyed by agent because that is what a report names - which is also this class's subject.
     */
    private ?RtNodeSourceMap $rtNodeSourceMap = null;

    /**
     * @var ?RtReplicaOriginMap Which node each replica this one holds came from, built lazily.
     *
     * Beside the map above and separate from it because the two are filled from opposite ends:
     * that one from what this node's own workers report, this one from what the peer transport
     * delivers. Here rather than in the daemon for the reason that one is - it is the shared
     * answer between the frames arriving on one loop pass and the link closing on another.
     */
    private ?RtReplicaOriginMap $rtReplicaOriginMap = null;

    /**
     * @var ?SourceReaderMap What RT collections the workers of this node read, built lazily.
     *
     * The reading half of the map above, kept apart from it because the two answer different
     * questions about different things: that one is keyed by agent and says what this NODE
     * owns, this one is keyed by worker and says where a frame is worth writing.
     */
    private ?SourceReaderMap $workerReaderMap = null;

    /**
     * @var ?array{rt: list<string>, db: list<string>} What this node reads that the mesh has not been told yet.
     *
     * What the map above adds up to, kept beside it so the loop can ask in one property read
     * whether there is anything to announce. The union moves far more rarely than the map does -
     * a page subscribing on a second worker changes who reads a collection, not whether this node
     * does - so this stays null through nearly every report, and the master pays a null check per
     * pass instead of walking the map ({@see consumeChangedReaderInterest()}).
     */
    private ?array $changedReaderInterest = null;

    /**
     * @var ?ProtectedModeAgentStopSink Who to tell that an agent stopped, or null when nobody asked.
     *
     * Registered by {@see DaemonManager} at start and left null everywhere else, exactly like the
     * relays the cluster context holds: an agent stop is this class's own event, and one watcher of
     * the freeze wants to hear it the moment it happens rather than at the next look at the roster.
     */
    private ?ProtectedModeAgentStopSink $agentStopSink = null;

    /**
     * Create agent daemon instance (factory method)
     *
     * Must be implemented in child classes to create specific agent daemon types.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentDaemonInterface Agent daemon instance
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws HilosException Whatever the project's factory raises, a missing agent index among it
     */
    abstract protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface;

    /**
     * @param ?string $agentType Agent type (null for non-agent sources like DB sync)
     * @param ?string $agentIndex Agent index (optional)
     * @return ?string Agent ID (format: "type" or "type:index") or null if agentType is null
     */
    public function buildAgentId(?string $agentType, ?string $agentIndex): ?string
    {
        if ($agentType === null) {
            return null;
        }
        return $agentIndex !== null ? $agentType . AgentConstants::ID_SEPARATOR . $agentIndex : $agentType;
    }

    /**
     * Registers who is told that an agent on this node stopped.
     *
     * One sink and not a list, mirroring the relays the cluster context registers: the fact has
     * exactly one watcher in the framework, and a registry for it would be machinery in front of
     * a single call.
     *
     * @param ProtectedModeAgentStopSink $sink Who to tell when an agent stops
     */
    public function registerAgentStopSink(ProtectedModeAgentStopSink $sink): void
    {
        $this->agentStopSink = $sink;
    }

    /**
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     * @return int Worker ID (negative = monopolistic, positive = regular)
     */
    public function calculateWorkerId(int $workerIndex, bool $isMonopolistic): int
    {
        return $isMonopolistic ? -$workerIndex : $workerIndex;
    }

    /**
     * Derives worker placement from a workerId.
     *
     * @param int $workerId Worker ID (negative = monopolistic, positive = regular)
     * @return WorkerInfo Worker index and monopolistic flag
     */
    public function extractWorkerInfo(int $workerId): WorkerInfo
    {
        return new WorkerInfo(abs($workerId), $workerId < 0);
    }

    /**
     * @param string $agentId Agent ID
     * @param AgentDaemonInterface $agentDaemon Agent daemon instance
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     */
    public function addAgent(string $agentId, AgentDaemonInterface $agentDaemon, int $workerIndex, bool $isMonopolistic): void
    {
        $this->agentDaemons[$agentId] = $agentDaemon;
        $this->agentToWorker[$agentId] = $this->calculateWorkerId($workerIndex, $isMonopolistic);
    }

    /**
     * @param string $agentId Agent ID
     */
    public function removeAgent(string $agentId): void
    {
        unset($this->agentDaemons[$agentId]);
        unset($this->agentToWorker[$agentId]);
        unset($this->startedAgentIds[$agentId]);
    }

    /**
     * @param string $agentId Agent ID
     * @return ?AgentDaemonInterface Agent daemon instance or null if not found
     */
    public function getAgent(string $agentId): ?AgentDaemonInterface
    {
        return $this->agentDaemons[$agentId] ?? null;
    }

    /**
     * @param string $agentId Agent ID
     * @return ?int Worker ID (negative = monopolistic, positive = regular) or null if not found
     */
    public function getAgentWorkerId(string $agentId): ?int
    {
        return $this->agentToWorker[$agentId] ?? null;
    }

    /**
     * Returns worker placement for an agent, or null when the agent is not mapped to a worker.
     *
     * @param string $agentId Agent ID
     * @return ?WorkerInfo Worker index and monopolistic flag, or null if not found
     */
    public function getAgentWorkerInfo(string $agentId): ?WorkerInfo
    {
        $workerId = $this->getAgentWorkerId($agentId);
        if ($workerId === null) {
            return null;
        }

        return $this->extractWorkerInfo($workerId);
    }

    /**
     * @param string $agentId Agent ID
     * @return bool True if agent exists
     */
    public function hasAgent(string $agentId): bool
    {
        return isset($this->agentDaemons[$agentId]);
    }

    /**
     * @param string $agentId Agent ID
     * @return bool True once the agent reported agent_started, so its onStart has completed
     */
    public function isAgentStarted(string $agentId): bool
    {
        return isset($this->startedAgentIds[$agentId]);
    }

    /**
     * @return array<string, AgentDaemonInterface> All agent daemons indexed by agent ID
     */
    public function getAgents(): array
    {
        return $this->agentDaemons;
    }

    /**
     * @return int Number of active agent daemons
     */
    public function getAgentCount(): int
    {
        return count($this->agentDaemons);
    }

    /**
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     * @return int Number of agents on worker
     */
    public function getAgentCountOnWorker(int $workerIndex, bool $isMonopolistic): int
    {
        $targetWorkerId = $this->calculateWorkerId($workerIndex, $isMonopolistic);
        $count = 0;

        foreach ($this->agentToWorker as $agentId => $workerId) {
            if ($workerId === $targetWorkerId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Builds a throwaway agent daemon without registering it, to read its type-level
     * contract (e.g. required capabilities) before deciding where to place it.
     *
     * Distinct from {@see createAndAddAgent()}, which registers the daemon and links it to a
     * worker; this one has no side effects on the manager's state.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentDaemonInterface Transient agent daemon instance
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws HilosException Whatever the project's factory raises, a missing agent index among it
     */
    public function instantiateAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return $this->createAgentDaemon($agentType, $agentIndex);
    }

    /**
     * Returns the agent daemon already registered for this id, or creates and registers a new one.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     * @return AgentDaemonInterface Created or existing agent daemon instance
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws HilosException Whatever the project's factory raises, a missing agent index among it
     */
    public function createAndAddAgent(string $agentType, ?string $agentIndex, int $workerIndex, bool $isMonopolistic): AgentDaemonInterface
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        // Check if agent already exists
        if ($this->hasAgent($agentId)) {
            return $this->getAgent($agentId);
        }

        // Create agent daemon
        $agentDaemon = $this->createAgentDaemon($agentType, $agentIndex);
        $this->addAgent($agentId, $agentDaemon, $workerIndex, $isMonopolistic);

        return $agentDaemon;
    }

    /**
     * Runs the daemon-side onStart hook for an already-registered agent, records it as started, and logs it.
     *
     * @param WorkerAgentStartedDTO $dto DTO with agent started data
     * @throws AgentDaemonNotRegisteredException When the worker reports an agent the manager never registered
     */
    public function handleAgentStarted(WorkerAgentStartedDTO $dto): void
    {
        $agentId = $dto->agentId;

        // The worker only reports agents the daemon already registered; a missing one is a registration bug
        if (!$this->hasAgent($agentId)) {
            throw new AgentDaemonNotRegisteredException($agentId);
        }

        $this->getAgent($agentId)?->onStart();
        $this->startedAgentIds[$agentId] = true;
        $workerIndex = $this->getAgentWorkerInfo($agentId)?->workerIndex ?? 'unknown';

        Logger::info("Agent '{$agentId}' started on worker #{$workerIndex}");
    }

    /**
     * Handle agent_stopped signal from worker
     *
     * Removes agent daemon and calls onStop().
     *
     * @param WorkerAgentStoppedDTO $dto DTO with agent stopped data
     */
    public function handleAgentStopped(WorkerAgentStoppedDTO $dto): void
    {
        if (!$this->hasAgent($dto->agentId)) {
            return;
        }

        $workerIndex = $this->getAgentWorkerInfo($dto->agentId)?->workerIndex ?? 'unknown';
        $this->getAgent($dto->agentId)?->onStop();
        $this->removeAgent($dto->agentId);

        // Told after the removal, so a sink that looks at the roster finds it already gone. The
        // one sink there is watches a freeze whose initiator may legally start again, which is
        // why this fact has to travel as an event and not as something re-read later (HIL-482).
        $this->agentStopSink?->onAgentStopped($dto->agentId);

        // The placement map is told separately rather than through that sink, which belongs to
        // the freeze watchdog alone: an agent that stopped itself after its declared silence
        // (HIL-628) leaves a map naming a host it no longer runs on, and a second meaning inside
        // one seam is how the freeze would start reacting to ordinary idleness.
        $stopped = AgentId::fromId($dto->agentId);
        Hilos::$cluster?->placement()?->noteAgentStopped($stopped->type, $stopped->index);

        Logger::info("Agent '{$dto->agentId}' stopped on worker #{$workerIndex}");
    }

    /**
     * Handle worker signal message (agent_message wire type)
     *
     * Receives signal from worker and queues it in daemon's SignalRouter.
     * DaemonManager will process the signal and decide what to do with it
     * (routing to other agents, WebSocket delivery to clients, etc.).
     *
     * @param WorkerAgentMessageDTO $dto DTO containing signal from worker
     * @throws InvalidArgumentException When the worker's signal carries an empty name
     */
    public function handleAgentMessage(WorkerAgentMessageDTO $dto): void
    {
        // Simply queue signal in daemon's SignalRouter
        // DaemonManager will process it in dispatchSignals() and handle routing/delivery
        Hilos::$sr->queueSignal(
            signalSource: $dto->signal->signalSource,
            signalType: $dto->signal->signalType,
            signalName: $dto->signal->signalName,
            signalData: $dto->signal->data,
        );
    }

    /**
     * Handle DB sync created message from worker (worker-level broadcast to daemon + all workers).
     *
     * @param WorkerDbSyncCreatedMessageDTO $dto DTO with sync created data
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerDbSyncCreated(WorkerDbSyncCreatedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_SYNC_CREATED),
            signalName: new SignalName(SignalConstants::DB_SYNC_CREATED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle the access re-decision announcement from a worker (HIL-644).
     *
     * It queues; it must not act. The database sync of the flag that was just written travels
     * this same queue and was queued ahead of the announcement, so both leave the writing
     * worker in that order, are drained here in that order, and are written to each worker
     * link in that order. A frame acted on at receipt would overtake the sync, and a worker
     * would then re-decide the verdict against a flag it has not seen change.
     *
     * @param WorkerPageAccessReassessMessageDTO $dto DTO naming the user whose rights changed
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerPageAccessReassess(WorkerPageAccessReassessMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_USER),
            signalName: new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_USER),
            signalData: new PageAccessReassessUserSignalData($dto->userId),
        );
    }

    /**
     * Handle the by-connection re-decision announcement from a worker (HIL-652).
     *
     * It queues for the same reason its twin does, and here the reason is load-bearing rather
     * than precautionary: the runtime write that un-pointed these connections travels this same
     * queue and was queued ahead of the announcement. Acted on at receipt, the frame would
     * overtake that write, and a worker would re-judge the page while the connection still
     * answers to the person who just signed out - answering "allow" and re-sending the very
     * page the sign-out was meant to take away.
     *
     * @param WorkerPageAccessReassessConnectionsMessageDTO $dto DTO naming the connections that lost their person
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerPageAccessReassessConnections(
        WorkerPageAccessReassessConnectionsMessageDTO $dto,
    ): void {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_CONNECTIONS),
            signalName: new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_CONNECTIONS),
            signalData: new PageAccessReassessConnectionsSignalData($dto->acceptKeys),
        );
    }

    /**
     * Handle DB sync updated message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncUpdatedMessageDTO $dto DTO with sync updated data
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerDbSyncUpdated(WorkerDbSyncUpdatedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_SYNC_UPDATED),
            signalName: new SignalName(SignalConstants::DB_SYNC_UPDATED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle DB sync deleted message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncDeletedMessageDTO $dto DTO with sync deleted data
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerDbSyncDeleted(WorkerDbSyncDeletedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_SYNC_DELETED),
            signalName: new SignalName(SignalConstants::DB_SYNC_DELETED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle DB sync cleared message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncClearedMessageDTO $dto DTO with cleared collection data
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerDbSyncCleared(WorkerDbSyncClearedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_SYNC_CLEARED),
            signalName: new SignalName(SignalConstants::DB_SYNC_CLEARED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle the whole-database re-hydrate announcement from a worker (HIL-479).
     *
     * Queues the fact into the daemon's own router, from where the ordinary sync dispatch
     * applies it to the daemon and sends it on to every worker. The signal is sourced from the
     * database rather than from the announcing agent: by the time it is re-queued here, the
     * event belongs to the node, not to whoever noticed the swap first. The announcing agent is
     * still named in the payload, because it is the one waiting for the verdict (HIL-436).
     *
     * @param ?string $agentId Agent that awaits the barrier's verdict, null when nobody does
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerDbReHydrate(?string $agentId): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_REHYDRATE),
            signalName: new SignalName(SignalConstants::DB_REHYDRATE),
            signalData: new DbReHydrateSignalData($agentId),
        );
    }

    /**
     * Opens the re-hydrate barrier over the roster the daemon fanned the announcement out to.
     *
     * An announcement arriving while a round is still open replaces it: the older round is about
     * a database that has since been replaced again, so its answers no longer say anything about
     * what is on disk now. The initiator of the abandoned round stops hearing back and falls
     * through to its own deadline, which is the same fail-closed path a lost verdict already takes.
     *
     * @param ?string $agentId Agent that announced the swap here, null when another node announced it
     * @param ?string $replyToNodeId Node that announced the swap to this one, null when this node did
     * @param list<string> $participants Participant labels, from {@see ReHydrateRound}'s factories
     * @param float $deadline Wall-clock deadline on the {@see microtime()} scale
     */
    public function openReHydrateRound(
        ?string $agentId,
        ?string $replyToNodeId,
        array $participants,
        float $deadline,
    ): void {
        $round = new ReHydrateRound();
        $round->start($participants, $deadline);

        $this->reHydrateRound = $round;
        $this->reHydrateInitiator = $agentId;
        $this->reHydrateReplyToNodeId = $replyToNodeId;
    }

    /**
     * Records one participant's answer to the open re-hydrate barrier.
     *
     * A no-op when no round is open: an answer with no question is a late duplicate, and the
     * round itself drops those too.
     *
     * @param string $participant Participant label, from {@see ReHydrateRound}'s factories
     * @param bool $ok Whether that participant re-read its collections successfully
     * @param ?string $error Failure text when it did not
     */
    public function ackReHydrateParticipant(string $participant, bool $ok, ?string $error): void
    {
        $this->reHydrateRound?->ack($participant, $ok, $error);
    }

    /**
     * Takes a participant that disappeared off the open re-hydrate barrier.
     *
     * @param string $participant Participant label, from {@see ReHydrateRound}'s factories
     */
    public function dropReHydrateParticipant(string $participant): void
    {
        $this->reHydrateRound?->drop($participant);
    }

    /**
     * Handles one worker's answer to the re-hydrate announcement (HIL-436).
     *
     * The frame does not name its sender - it arrived on that worker's own link - so the index
     * comes from the {@see WorkerClient} that read it.
     *
     * @param int $workerIndex Index of the worker that answered
     * @param WorkerDbReHydratedDTO $dto That worker's verdict on re-reading its collections
     */
    public function handleWorkerDbReHydrated(int $workerIndex, WorkerDbReHydratedDTO $dto): void
    {
        $this->ackReHydrateParticipant(ReHydrateRound::workerParticipant($workerIndex), $dto->ok, $dto->error);
    }

    /**
     * Ends the open re-hydrate barrier once nobody is left to wait for, and reports its verdict.
     *
     * Consuming rather than peeking: the round exists to be answered exactly once, and leaving a
     * settled one in place would send the same verdict again on every following tick.
     *
     * @param float $now Current time on the {@see microtime()} scale
     * @return ?ReHydrateVerdict Verdict and who is waiting for it, or null while the barrier still waits
     */
    public function pollReHydrateVerdict(float $now): ?ReHydrateVerdict
    {
        $round = $this->reHydrateRound;
        if ($round === null) {
            return null;
        }

        $round->expire($now);
        if (!$round->isSettled()) {
            return null;
        }

        $agentId = $this->reHydrateInitiator;
        $replyToNodeId = $this->reHydrateReplyToNodeId;
        $this->reHydrateRound = null;
        $this->reHydrateInitiator = null;
        $this->reHydrateReplyToNodeId = null;

        return new ReHydrateVerdict($agentId, $replyToNodeId, $round->isComplete(), $round->problems());
    }

    /**
     * Handle RT sync created message from worker (worker-level broadcast).
     *
     * @param WorkerRtSyncCreatedMessageDTO $dto DTO with sync created data
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerRtSyncCreated(WorkerRtSyncCreatedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::RT),
            signalType: new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
            signalName: new SignalName(SignalConstants::RT_SYNC_CREATED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle RT sync updated message from worker (worker-level broadcast).
     *
     * @param WorkerRtSyncUpdatedMessageDTO $dto DTO with sync updated data
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerRtSyncUpdated(WorkerRtSyncUpdatedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::RT),
            signalType: new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
            signalName: new SignalName(SignalConstants::RT_SYNC_UPDATED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle RT sync deleted message from worker (worker-level broadcast).
     *
     * @param WorkerRtSyncDeletedMessageDTO $dto DTO with sync deleted data
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    public function handleWorkerRtSyncDeleted(WorkerRtSyncDeletedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::RT),
            signalType: new SignalType(SignalTypeConstants::RT_SYNC_DELETED),
            signalName: new SignalName(SignalConstants::RT_SYNC_DELETED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Records what an agent started on this node took ownership of.
     *
     * @param WorkerRtSourceRegisteredDTO $dto DTO with the agent and the collections it owns
     */
    public function handleRtSourceRegistered(WorkerRtSourceRegisteredDTO $dto): void
    {
        $this->rtNodeSourceMap()->note(
            $dto->agentId,
            $dto->collectionKeys,
            $dto->partialCollectionKeys,
            $dto->keysByCollection,
        );
    }

    /**
     * Records everything one worker reads, of both kinds, replacing what it read before.
     *
     * The returned collections are the ones this worker has just started reading, and its link
     * owes each of them an answer: an RT one owes a snapshot, because the worker holds no copy of
     * a collection it never asked for and a delta would land on nothing; a DB one owes only the
     * word that it is in the map, because the rows are in the database it reads itself.
     *
     * Both kinds are noted on the one report rather than on a report each, because the frame
     * carries both and a second pass over it would be a second place for the map to disagree
     * with what the worker said.
     *
     * @param WorkerSourceInterestDTO $dto DTO with every RT and DB collection that worker reads
     * @param int $workerIndex Index of the worker that reported
     * @return array{rt: list<string>, db: list<string>} Collections that worker did not read before, keyed
     *     by the kind constants of {@see SourceChange}
     */
    public function handleSourceInterest(WorkerSourceInterestDTO $dto, int $workerIndex): array
    {
        $announced = $this->readerInterestUnion();
        $holderId = self::workerHolderId($workerIndex);
        $added = [
            SourceChange::KIND_RT => $this->workerReaderMap()->note(
                $holderId,
                SourceChange::KIND_RT,
                $dto->rtCollections,
            ),
            SourceChange::KIND_DB => $this->workerReaderMap()->note(
                $holderId,
                SourceChange::KIND_DB,
                $dto->dbCollections,
            ),
        ];
        $this->noteReaderInterestChange($announced);

        return $added;
    }

    /**
     * Drops what one worker read, because that worker is gone.
     *
     * Its own step next to {@see self::releaseRtSourcesOfWorker()} and not folded into it: a
     * worker owns collections through the agents it hosts and reads them through pages too, so
     * the two lists empty on the same event but neither implies the other.
     *
     * @param int $workerIndex Index of the worker whose link closed
     */
    public function releaseReaderInterestOfWorker(int $workerIndex): void
    {
        $announced = $this->readerInterestUnion();
        $this->workerReaderMap()->release(self::workerHolderId($workerIndex));
        $this->noteReaderInterestChange($announced);
    }

    /**
     * Records the union for the mesh when a report moved it, and stays quiet when it did not.
     *
     * The lists are compared as they stand rather than as sets: a union that came back in another
     * order says the same thing, and announcing it again costs one frame on an event that is
     * already rare, while a set that really changed cannot come back looking identical.
     *
     * The comparison is over the pair and not over one kind at a time, because the mesh is told
     * the pair: a node that took up a DB collection and dropped an RT one on the same report has
     * moved, and either half looked at alone would say it had not.
     *
     * @param array{rt: list<string>, db: list<string>} $announced What this node read before the report
     */
    private function noteReaderInterestChange(array $announced): void
    {
        $reads = $this->readerInterestUnion();
        if ($reads !== $announced) {
            $this->changedReaderInterest = $reads;
        }
    }

    /**
     * What the workers of this node read between them, of both kinds.
     *
     * @return array{rt: list<string>, db: list<string>} Collections read on this node, by kind
     */
    private function readerInterestUnion(): array
    {
        return [
            SourceChange::KIND_RT => $this->workerReaderMap()->collections(SourceChange::KIND_RT),
            SourceChange::KIND_DB => $this->workerReaderMap()->collections(SourceChange::KIND_DB),
        ];
    }

    /**
     * Hands over what this node reads, of both kinds, once, when it has changed.
     *
     * Consuming rather than asking twice, because the caller is the daemon loop and the answer is
     * a duty: what it takes here it has to announce, and an answer left in place would be
     * announced again on every pass. Null is not an empty list - a node whose last reader went
     * away reads nothing and must say so, or the mesh goes on sending it frames forever.
     *
     * @return ?array{rt: list<string>, db: list<string>} What this node reads, or null when the mesh already knows
     */
    public function consumeChangedReaderInterest(): ?array
    {
        $reads = $this->changedReaderInterest;
        $this->changedReaderInterest = null;

        return $reads;
    }

    /**
     * Names one worker as a holder of collections.
     *
     * A holder id is a plain string because the same map serves nodes as well as workers
     * ({@see SourceReaderMap}), so the prefix is what keeps a worker index from reading as a
     * node id in a map that ever held both.
     *
     * @param int $workerIndex Index of the worker
     * @return string Holder id of that worker
     */
    public static function workerHolderId(int $workerIndex): string
    {
        return 'worker:' . $workerIndex;
    }

    /**
     * Records that an agent stopped here and owns nothing any more.
     *
     * @param WorkerRtSourceReleasedDTO $dto DTO with the agent that stopped
     */
    public function handleRtSourceReleased(WorkerRtSourceReleasedDTO $dto): void
    {
        $this->rtNodeSourceMap()->release($dto->agentId);
    }

    /**
     * Drops what the agents of one worker owned, because that worker is gone.
     *
     * A worker that dies never gets to send its releases, and an ownership claim that outlives
     * the process holding it is worse than none: the node would go on refusing every replica
     * for a collection nobody here writes any more. The same reason a dead worker is taken off
     * the re-hydrate barrier rather than waited for.
     *
     * @param int $workerIndex Index of the worker whose link closed
     * @param bool $isMonopolistic Whether that worker was the monopolistic one
     */
    public function releaseRtSourcesOfWorker(int $workerIndex, bool $isMonopolistic): void
    {
        $workerId = $this->calculateWorkerId($workerIndex, $isMonopolistic);
        foreach ($this->agentToWorker as $agentId => $agentWorkerId) {
            if ($agentWorkerId === $workerId) {
                $this->rtNodeSourceMap()->release((string)$agentId);
            }
        }
    }

    /**
     * Returns what this node owns, as its workers have reported it.
     *
     * @return RtNodeSourceMap Map of the RT collections owned on this node
     */
    public function rtNodeSourceMap(): RtNodeSourceMap
    {
        return $this->rtNodeSourceMap ??= new RtNodeSourceMap();
    }

    /**
     * Returns which node each replica this one holds arrived from.
     *
     * @return RtReplicaOriginMap Map of the RT rows replicated onto this node, by origin
     */
    public function rtReplicaOriginMap(): RtReplicaOriginMap
    {
        return $this->rtReplicaOriginMap ??= new RtReplicaOriginMap();
    }

    /**
     * Returns which of this node's workers read which RT collection.
     *
     * Kept here for the reason the ownership map is kept here: a worker link talks to this
     * manager and to nothing else, so this is the one place both halves of what a worker
     * reports can be written down.
     *
     * @return SourceReaderMap Map of the RT collections read by the workers of this node
     */
    public function workerReaderMap(): SourceReaderMap
    {
        return $this->workerReaderMap ??= new SourceReaderMap();
    }
}
