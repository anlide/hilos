<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Constants\WorkerConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentDaemonNotRegisteredException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\ReHydrateRound;
use Hilos\Environment\Exception\EnvException;
use Hilos\Log\LogWriteLevelApplier;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtStaleness;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\ProtectedModeReadyDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbInterestReadyMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupJoinDTO;
use Hilos\Socket\Worker\DTO\WorkerLogWriteLevelDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeDisableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeEnableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModePassDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeProgressDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeRefreezeDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeVerifyDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSnapshotMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerSessionCarryOverDeferredDTO;
use Hilos\Socket\Worker\DTO\WorkerSessionCarryOverDoneDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;

/**
 * WorkerClient - Represents a single worker connection.
 *
 * Handles reading messages from workers and writing responses.
 * Created by WorkerServer when accepting new worker connections.
 */
class WorkerClient extends AbstractClient implements WorkerClientInterface
{
    /** @var int Worker index */
    private int $workerIndex = 0;

    /** @var bool Whether worker is monopolistic */
    private bool $isMonopolistic = false;

    /** @var bool Whether worker is registered */
    private bool $isRegistered = false;

    /** @var AgentManagerDaemon Agent manager daemon instance */
    private AgentManagerDaemon $agentManager;

    /** @var float Connection time (microtime) */
    private float $connectTime;

    /** @var float Registration timeout in seconds */
    private float $registrationTimeout = 10.0;

    /**
     * Create worker client with socket and agent manager.
     *
     * @param resource $socket Client socket
     * @param AgentManagerDaemon $agentManager Agent manager daemon instance
     * @throws EnvException When socket read buffer env value is missing or invalid
     */
    public function __construct($socket, AgentManagerDaemon $agentManager)
    {
        parent::__construct($socket);
        $this->agentManager = $agentManager;
        $this->connectTime = microtime(true);
    }

    /**
     * Set worker index
     *
     * @param int $workerIndex Worker index
     */
    public function setWorkerIndex(int $workerIndex): void
    {
        $this->workerIndex = $workerIndex;
    }

    /**
     * Get worker index
     *
     * @return int Worker index
     */
    public function getWorkerIndex(): int
    {
        return $this->workerIndex;
    }

    /**
     * Get worker ID
     *
     * Worker ID is calculated as: negative = monopolistic, positive = regular
     *
     * @return int Worker ID (negative = monopolistic, positive = regular)
     */
    public function getWorkerId(): int
    {
        return $this->isMonopolistic ? -$this->workerIndex : $this->workerIndex;
    }

    /**
     * Set whether worker is monopolistic
     *
     * @param bool $isMonopolistic True if monopolistic
     */
    public function setIsMonopolistic(bool $isMonopolistic): void
    {
        $this->isMonopolistic = $isMonopolistic;
    }

    /**
     * Check if worker is monopolistic
     *
     * @return bool True if monopolistic
     */
    public function isMonopolistic(): bool
    {
        return $this->isMonopolistic;
    }

    /**
     * Check if worker is registered
     *
     * @return bool True if registered
     */
    public function isRegistered(): bool
    {
        return $this->isRegistered;
    }

    /**
     * Extract complete JSON messages from the read buffer and dispatch worker protocol handlers.
     *
     * @throws SocketException When read buffer or JSON depth exceeds limits
     * @throws InvalidFormatException When a frame does not decode, names no known type,
     *     or lacks a field its DTO needs
     * @throws AgentDaemonCreationFailedException When agent creation fails during message handling
     * @throws HilosException When buffered wire input refuses to become a DTO, or a re-queued
     *     frame carries an empty signal name
     */
    protected function processReadBuffer(): void
    {
        while ($this->readBuffer !== '') {
            $message = $this->extractCompleteJsonMessage($this->readBuffer);
            if ($message === null) {
                // Incomplete message, wait for more data
                break;
            }

            $this->processMessage($message);
        }
    }

    /**
     * Process message from worker.
     *
     * @param string $message Complete JSON message payload
     * @throws InvalidFormatException When a frame does not decode, names no known type,
     *     or lacks a field its DTO needs
     * @throws InvalidArgumentException When a frame the master re-queues carries an empty signal name
     * @throws AgentDaemonCreationFailedException When agent creation fails during message handling
     */
    private function processMessage(string $message): void
    {
        // Log received message data for debugging
        Logger::debug("Received message from worker: " . $message);

        // Parse JSON message and create appropriate DTO
        $workerDTO = WorkerDTO::factoryWorkerDTO($message);

        // Handle different message types using match with instanceof
        match (true) {
            $workerDTO instanceof WorkerRegisterDTO => $this->handleWorkerRegisterMessage($workerDTO),
            $workerDTO instanceof WorkerLogWriteLevelDTO => $this->handleWorkerLogWriteLevelMessage($workerDTO),
            $workerDTO instanceof WorkerAgentStartedDTO => $this->handleAgentStartedMessage($workerDTO),
            $workerDTO instanceof WorkerAgentStoppedDTO => $this->handleAgentStoppedMessage($workerDTO),
            $workerDTO instanceof WorkerAgentMessageDTO => $this->handleAgentMessageMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncCreatedMessageDTO => $this->handleWorkerDbSyncCreatedMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncUpdatedMessageDTO => $this->handleWorkerDbSyncUpdatedMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncDeletedMessageDTO => $this->handleWorkerDbSyncDeletedMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncClearedMessageDTO => $this->handleWorkerDbSyncClearedMessage($workerDTO),
            $workerDTO instanceof WorkerDbReHydrateMessageDTO => $this->handleWorkerDbReHydrateMessage($workerDTO),
            $workerDTO instanceof WorkerPageAccessReassessMessageDTO
                => $this->handleWorkerPageAccessReassessMessage($workerDTO),
            $workerDTO instanceof WorkerPageAccessReassessConnectionsMessageDTO
                => $this->handleWorkerPageAccessReassessConnectionsMessage($workerDTO),
            $workerDTO instanceof WorkerDbReHydratedDTO => $this->handleWorkerDbReHydratedMessage($workerDTO),
            $workerDTO instanceof WorkerRtSyncCreatedMessageDTO => $this->handleWorkerRtSyncCreatedMessage($workerDTO),
            $workerDTO instanceof WorkerRtSyncUpdatedMessageDTO => $this->handleWorkerRtSyncUpdatedMessage($workerDTO),
            $workerDTO instanceof WorkerRtSyncDeletedMessageDTO => $this->handleWorkerRtSyncDeletedMessage($workerDTO),
            $workerDTO instanceof WorkerRtSourceRegisteredDTO => $this->handleWorkerRtSourceRegisteredMessage($workerDTO),
            $workerDTO instanceof WorkerRtSourceReleasedDTO => $this->handleWorkerRtSourceReleasedMessage($workerDTO),
            $workerDTO instanceof WorkerSourceInterestDTO => $this->handleWorkerSourceInterestMessage($workerDTO),
            $workerDTO instanceof WorkerGroupJoinDTO => $this->handleGroupJoinMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeEnableDTO => $this->handleProtectedModeEnableMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeDisableDTO => $this->handleProtectedModeDisableMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeVerifyDTO => $this->handleProtectedModeVerifyMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeProgressDTO => $this->handleProtectedModeProgressMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModePassDTO => $this->handleProtectedModePassMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeRefreezeDTO => $this->handleProtectedModeRefreezeMessage($workerDTO),
            $workerDTO instanceof WorkerSessionCarryOverDeferredDTO
                => $this->handleSessionCarryOverDeferredMessage($workerDTO),
            $workerDTO instanceof WorkerSessionCarryOverDoneDTO
                => $this->handleSessionCarryOverDoneMessage($workerDTO),
            default => Logger::error("Unknown message type received from worker: " . get_class($workerDTO)),
        };
    }

    /**
     * Handle worker register message
     *
     * @param WorkerRegisterDTO $dto Signal data
     */
    private function handleWorkerRegisterMessage(WorkerRegisterDTO $dto): void
    {
        $this->setWorkerIndex($dto->workerIndex);
        $this->setIsMonopolistic($dto->monopolistic);
        $this->isRegistered = true;

        // Log worker registration on daemon side
        $workerType = $dto->monopolistic ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR;
        Logger::debug("Worker #{$dto->workerIndex} registered [type={$workerType}]");

        // Send registration confirmation to worker using DTO
        $responseDto = new WorkerRegisteredDTO(
            workerIndex: $dto->workerIndex,
            monopolistic: $dto->monopolistic,
        );
        $this->send($responseDto->toJson());
    }

    /**
     * Handle the write level a worker reports.
     *
     * The master cannot read the setting behind this itself - it is forbidden the database - so
     * what a worker tells it is the only word it gets. The applier writes a line only when the
     * value differs from the one in force, which is what keeps a node with several workers from
     * saying the same thing once per worker.
     *
     * A name that is not a level changes nothing: it can only mean the frame was built wrong, and
     * silencing daemon.log on the strength of a malformed frame is worse than ignoring it.
     *
     * @param WorkerLogWriteLevelDTO $dto Level the worker writes from
     */
    private function handleWorkerLogWriteLevelMessage(WorkerLogWriteLevelDTO $dto): void
    {
        $level = LogLevel::fromName($dto->level);
        if ($level === null) {
            Logger::error("Worker reported an unknown log write level: {$dto->level}");

            return;
        }

        LogWriteLevelApplier::applyReported($level, $this->getWorkerIndex());
    }

    /**
     * Handle agent started message
     *
     * A report naming an agent the roster does not have is answered here rather than allowed
     * out, because the guard above this one is the wrong size for it: an exception leaving this
     * method reaches {@see DaemonManager::onClientRead()}, which discards the client - and the
     * client is the whole worker. One stale line about one agent would cost the process and
     * every other agent on it, which is how a freeze came to kill healthy workers.
     *
     * It is a race and not only a bug. A freeze deregisters an agent while its start report is
     * already on the wire, so the report lands in a roster that no longer names it; the stop
     * the freeze sent is on its way down the same link. The stop below is for the other case,
     * where nothing was sent: an agent the master does not know must not go on running,
     * ticking and holding truth sources where nothing will ever address or stop it. Sending it
     * twice is harmless, and saying nothing at all is not.
     *
     * @param WorkerAgentStartedDTO $dto DTO with agent started data
     * @throws AgentDaemonCreationFailedException If agent creation fails
     */
    private function handleAgentStartedMessage(WorkerAgentStartedDTO $dto): void
    {
        try {
            $this->agentManager->handleAgentStarted($dto);
        } catch (AgentDaemonNotRegisteredException $notRegistered) {
            // Kept loud: the roster missing an agent a worker just started is worth a line
            // whichever of the two cases produced it.
            Logger::error(
                "Worker #{$this->workerIndex} reported a start of '{$dto->agentId}', which this"
                . ' roster does not have; stopping it there: ' . $notRegistered->getMessage(),
            );

            $orphan = AgentId::fromId($dto->agentId);
            $this->sendAgentStop($orphan->type, $orphan->index);
        }
    }

    /**
     * Handle agent stopped message
     *
     * @param WorkerAgentStoppedDTO $dto DTO with agent stopped data
     */
    private function handleAgentStoppedMessage(WorkerAgentStoppedDTO $dto): void
    {
        $this->agentManager->handleAgentStopped($dto);
    }

    /**
     * Handle agent_message (worker signal) message from worker
     *
     * Forwards to AgentManagerDaemon which queues the signal in daemon's SignalRouter.
     *
     * @param WorkerAgentMessageDTO $dto DTO with agent message data
     */
    private function handleAgentMessageMessage(WorkerAgentMessageDTO $dto): void
    {
        $this->agentManager->handleAgentMessage($dto);
    }

    /**
     * Handle DB sync created message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncCreatedMessageDTO $dto DTO with sync created data
     */
    private function handleWorkerDbSyncCreatedMessage(WorkerDbSyncCreatedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbSyncCreated($dto);
    }

    /**
     * Handle DB sync updated message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncUpdatedMessageDTO $dto DTO with sync updated data
     */
    private function handleWorkerDbSyncUpdatedMessage(WorkerDbSyncUpdatedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbSyncUpdated($dto);
    }

    /**
     * Handle DB sync deleted message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncDeletedMessageDTO $dto DTO with sync deleted data
     */
    private function handleWorkerDbSyncDeletedMessage(WorkerDbSyncDeletedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbSyncDeleted($dto);
    }

    /**
     * Handle DB sync cleared message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncClearedMessageDTO $dto DTO with cleared collection data
     */
    private function handleWorkerDbSyncClearedMessage(WorkerDbSyncClearedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbSyncCleared($dto);
    }

    /**
     * Handle the whole-database re-hydrate announcement from a worker (HIL-479).
     *
     * The frame carries nothing but its announcer, so nothing is unwrapped here beyond that: the
     * daemon has to learn that the database was replaced, pass the fact on, and remember who to
     * report back to once every process has re-read (HIL-436).
     *
     * @param WorkerDbReHydrateMessageDTO $dto Announcement naming the agent that replaced the database, if any
     */
    private function handleWorkerDbReHydrateMessage(WorkerDbReHydrateMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbReHydrate($dto->agentId);
    }

    /**
     * Handle the access re-decision announcement from a worker (HIL-644).
     *
     * The frame names a person and no page, because the announcing worker holds neither the
     * other workers' subscriptions nor an answer to who is behind a connection. Nothing is
     * decided here: the master queues the fact and fans it back out to every worker of the
     * node, each of which sweeps its own mirror.
     *
     * @param WorkerPageAccessReassessMessageDTO $dto Announcement naming the user whose rights changed
     * @throws InvalidArgumentException When the queued announcement carries an empty name
     */
    private function handleWorkerPageAccessReassessMessage(WorkerPageAccessReassessMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerPageAccessReassess($dto);
    }

    /**
     * Handle the by-connection re-decision announcement from a worker (HIL-652).
     *
     * The frame names sockets and no page, and unlike its twin it names nobody at all - that is
     * what lets it outlive the write it follows. Nothing is decided here: the master queues the
     * fact and fans it back out to every worker of the node.
     *
     * @param WorkerPageAccessReassessConnectionsMessageDTO $dto Announcement naming the connections that lost their person
     * @throws InvalidArgumentException When the queued announcement carries an empty name
     */
    private function handleWorkerPageAccessReassessConnectionsMessage(
        WorkerPageAccessReassessConnectionsMessageDTO $dto,
    ): void {
        $this->agentManager->handleWorkerPageAccessReassessConnections($dto);
    }

    /**
     * Handle one worker's answer to the re-hydrate announcement (HIL-436).
     *
     * The answering worker is identified by the link the frame arrived on, not by the frame, so
     * a worker cannot answer on somebody else's behalf.
     *
     * @param WorkerDbReHydratedDTO $dto That worker's verdict on re-reading its collections
     */
    private function handleWorkerDbReHydratedMessage(WorkerDbReHydratedDTO $dto): void
    {
        $this->agentManager->handleWorkerDbReHydrated($this->workerIndex, $dto);
    }

    /**
     * Send the aggregated re-hydrate verdict to the worker hosting the announcing agent (HIL-436).
     *
     * @param DbReHydrateCompleteDTO $dto Verdict addressed to the agent that announced the swap
     */
    public function sendDbReHydrateComplete(DbReHydrateCompleteDTO $dto): void
    {
        Logger::debug(
            "Sending db_rehydrate_complete signal to worker [agentId={$dto->agentId}]"
            . " [complete=" . ($dto->complete ? 'true' : 'false') . "] [workerIndex={$this->workerIndex}]",
        );

        $this->send($dto->toJson());
    }

    /**
     * Handle RT sync created message from worker (worker-level broadcast).
     *
     * @param WorkerRtSyncCreatedMessageDTO $dto DTO with sync created data
     */
    private function handleWorkerRtSyncCreatedMessage(WorkerRtSyncCreatedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerRtSyncCreated($dto);
    }

    /**
     * Handle RT sync updated message from worker (worker-level broadcast).
     *
     * @param WorkerRtSyncUpdatedMessageDTO $dto DTO with sync updated data
     */
    private function handleWorkerRtSyncUpdatedMessage(WorkerRtSyncUpdatedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerRtSyncUpdated($dto);
    }

    /**
     * Handle RT sync deleted message from worker (worker-level broadcast).
     *
     * @param WorkerRtSyncDeletedMessageDTO $dto DTO with sync deleted data
     */
    private function handleWorkerRtSyncDeletedMessage(WorkerRtSyncDeletedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerRtSyncDeleted($dto);
    }

    /**
     * Handle the RT collections a started agent owns, reported by its worker.
     *
     * @param WorkerRtSourceRegisteredDTO $dto DTO with the agent and the collections it took
     */
    private function handleWorkerRtSourceRegisteredMessage(WorkerRtSourceRegisteredDTO $dto): void
    {
        $this->agentManager->handleRtSourceRegistered($dto);
    }

    /**
     * Handle the release of the RT collections a stopped agent owned.
     *
     * @param WorkerRtSourceReleasedDTO $dto DTO with the agent that stopped
     */
    private function handleWorkerRtSourceReleasedMessage(WorkerRtSourceReleasedDTO $dto): void
    {
        $this->agentManager->handleRtSourceReleased($dto);
    }

    /**
     * Handle everything one worker reads, and answer the collections it has just taken up.
     *
     * The two steps are one and nothing may be written to this worker between them: the map
     * entry is what starts addressing deltas here, and a delta reaching the worker before the
     * answer would land on a collection it holds nothing of. Sending the answer second is what
     * makes that impossible - by the time the frame after it can be produced, the state it
     * applies to is already queued ahead of it on this same socket.
     *
     * What the answer carries is where the two kinds part (HIL-750). An RT collection is sent
     * its rows, because the master's replica is the only copy the worker can be given. A DB
     * collection is sent a bare confirmation: its rows are in the database the worker reads for
     * itself, so the only thing it is missing is the word that it is in the map.
     *
     * @param WorkerSourceInterestDTO $dto DTO with every RT and DB collection that worker reads
     */
    private function handleWorkerSourceInterestMessage(WorkerSourceInterestDTO $dto): void
    {
        $added = $this->agentManager->handleSourceInterest($dto, $this->workerIndex);

        foreach ($added[SourceChange::KIND_RT] as $collectionKey) {
            $this->send((new WorkerRtSnapshotMessageDTO(
                collectionKey: $collectionKey,
                rows: RtSnapshot::rows($collectionKey),
                staleRows: RtStaleness::staleRows($collectionKey),
            ))->toJson());
        }

        foreach ($added[SourceChange::KIND_DB] as $collectionKey) {
            $this->send((new WorkerDbInterestReadyMessageDTO(collectionKey: $collectionKey))->toJson());
        }
    }

    /**
     * Records a group membership the worker admitted.
     *
     * The master keeps the registry every fan-out is resolved against, but it stopped writing
     * to it off the client frame: a join is judged in the worker that owns the group, and only
     * that side knows who is behind the socket and what full name their identity builds. So
     * this is the master doing as it is told, and the frame arrives only for a join that has
     * already passed the group's own admission.
     *
     * @param WorkerGroupJoinDTO $dto DTO with the full group name, the connection and the join params
     */
    private function handleGroupJoinMessage(WorkerGroupJoinDTO $dto): void
    {
        Hilos::$sr?->subscribeToGroup($dto->data->group, new WebSocketGroupSubscribeSignalDTO(
            acceptKey: $dto->data->acceptKey,
            group: $dto->data->group,
            params: $dto->data->params,
        ));
    }

    /**
     * Handle protected-mode enable request from an initiator worker.
     *
     * Hands the carried initiator identity to this node's freeze switch, which freezes the single
     * node on the spot, or enables locally when this node leads a cluster and forwards the request
     * to the current leader otherwise. A node without a switch ignores the request.
     *
     * @param WorkerProtectedModeEnableDTO $dto DTO with the initiator identity and operation
     */
    private function handleProtectedModeEnableMessage(WorkerProtectedModeEnableDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestEnable($dto->data);
    }

    /**
     * Handle protected-mode disable request from an initiator worker.
     *
     * Hands the carried initiator identity to this node's freeze switch, which authorizes the
     * release against the recorded initiator on a single node, or lifts locally when this node
     * leads and forwards to the current leader otherwise. A node without a switch ignores it.
     *
     * @param WorkerProtectedModeDisableDTO $dto DTO with the identity of the agent asking for the release
     */
    private function handleProtectedModeDisableMessage(WorkerProtectedModeDisableDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestDisable($dto->data);
    }

    /**
     * Handle protected-mode verify request from an initiator worker.
     *
     * The frame an initiator sends when its destructive operation ends: the freeze moves to its
     * verification window rather than lifting, so the system opens to pass holders only. Routed
     * and authorized exactly like the disable above.
     *
     * @param WorkerProtectedModeVerifyDTO $dto DTO with the identity of the agent asking for the window
     */
    private function handleProtectedModeVerifyMessage(WorkerProtectedModeVerifyDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestVerify($dto->data);
    }

    /**
     * Handle a protected-mode progress mark from an initiator worker.
     *
     * The one frame of the set that asks for nothing: it stamps the freeze row so a watchdog can
     * tell an operation that is legitimately long from one that hung. Routed and authorized
     * exactly like the requests around it, and dropped just as quietly when this node holds no
     * freeze - a mark under no freeze is a report to nobody, not an error.
     *
     * @param WorkerProtectedModeProgressDTO $dto DTO with the identity of the agent reporting the progress
     */
    private function handleProtectedModeProgressMessage(WorkerProtectedModeProgressDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestProgress($dto->data);
    }

    /**
     * Handle protected-mode pass request from an initiator worker.
     *
     * Records one more minted pass on the freeze row. Only its hash travels here; the clear key
     * never leaves the operator's terminal.
     *
     * @param WorkerProtectedModePassDTO $dto DTO with the minting agent identity and the pass hash
     */
    private function handleProtectedModePassMessage(WorkerProtectedModePassDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestPass($dto->data);
    }

    /**
     * Handle protected-mode refreeze request from an initiator worker.
     *
     * The other exit from the verification window: the system closes back to a full freeze, every
     * pass is void, and another destructive operation may run.
     *
     * @param WorkerProtectedModeRefreezeDTO $dto DTO with the identity of the agent asking to close back
     */
    private function handleProtectedModeRefreezeMessage(WorkerProtectedModeRefreezeDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestRefreeze($dto->data);
    }

    /**
     * Handle a restore's report that it left logins for the sessions library.
     *
     * The debt the lift of this node's freeze waits on: until the library says those logins are
     * back in the restored database, telling the browsers to reload signs their owners out. Only
     * the node that ran the restore ever hears this frame, which is what keeps every other lift
     * immediate (HIL-771).
     *
     * @param WorkerSessionCarryOverDeferredDTO $dto DTO with the number of logins left queued
     */
    private function handleSessionCarryOverDeferredMessage(WorkerSessionCarryOverDeferredDTO $dto): void
    {
        Hilos::$cluster?->protectedModeLiftAnnouncer()?->noteSessionsDeferred($dto->data->sessions);
    }

    /**
     * Handle the sessions library's report that those logins have been dealt with.
     *
     * Releases a lift held for them on the spot, so the ordinary case costs the browsers nothing
     * beyond the library's own start. Dropped quietly when nothing was waiting - the usual case,
     * where the library came back during the verification window and the operator opened the node
     * long afterwards.
     *
     * @param WorkerSessionCarryOverDoneDTO $dto DTO with the logins carried, the logins lost and the logins that came back with the archive
     */
    private function handleSessionCarryOverDoneMessage(WorkerSessionCarryOverDoneDTO $dto): void
    {
        Hilos::$cluster?->protectedModeLiftAnnouncer()?->noteSessionsCarriedOver(
            $dto->data->carried,
            $dto->data->dropped,
            $dto->data->kept,
        );
    }

    /**
     * Send message to worker
     *
     * @param string $message Message to send
     */
    public function send(string $message): void
    {
        $this->writeBuffer .= $message . "\n";
    }

    /**
     * Send agent_start signal to worker
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @param list<string> $liveAcceptKeys Accept keys of the node's live sockets, for the roster reconcile (HIL-664)
     */
    public function sendAgentStart(string $agentType, ?string $agentIndex = null, array $liveAcceptKeys = []): void
    {
        // external-boundary: the neutral element of the agent id — a singleton is the bare type
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        Logger::debug("Sending agent_start signal to worker [agentId={$agentId}] [agentType={$agentType}]"
            . ' [agentIndex=' . ($agentIndex ?? 'null') . "] [workerIndex={$this->workerIndex}]");

        $dto = new AgentStartDTO(
            agentId: $agentId,
            liveAcceptKeys: $liveAcceptKeys,
        );

        $this->send($dto->toJson());
    }

    /**
     * Send agent_stop signal to worker
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     */
    public function sendAgentStop(string $agentType, ?string $agentIndex = null): void
    {
        // external-boundary: the neutral element of the agent id — a singleton is the bare type
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        Logger::debug("Sending agent_stop signal to worker [agentId={$agentId}] [workerIndex={$this->workerIndex}]");

        $dto = new AgentStopDTO(
            agentId: $agentId,
        );

        $this->send($dto->toJson());
    }

    /**
     * Send protected-mode ready relay to worker for a specific initiator agent.
     *
     * @param string $agentType Initiator agent type
     * @param ?string $agentIndex Initiator agent index, or null for a singleton agent
     */
    public function sendProtectedModeReady(string $agentType, ?string $agentIndex = null): void
    {
        // external-boundary: the neutral element of the agent id — a singleton is the bare type
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        Logger::debug("Sending protected_mode_ready signal to worker [agentId={$agentId}] [workerIndex={$this->workerIndex}]");

        $dto = new ProtectedModeReadyDTO(
            agentId: $agentId,
        );

        $this->send($dto->toJson());
    }

    /**
     * Tick method - check registration timeout
     *
     * Checks if registration timeout has been exceeded and closes connection if so.
     */
    public function onTick(): void
    {
        // Skip if already registered or already closing
        if ($this->isRegistered || $this->shouldClose) {
            return;
        }

        // Check if registration timeout exceeded
        $currentTime = microtime(true);
        if (($currentTime - $this->connectTime) >= $this->registrationTimeout) {
            Logger::error("Worker client registration timeout exceeded, disconnecting");
            $this->shouldClose = true;
        }
    }

    /**
     * Called when socket connection is successfully closed.
     *
     * A worker that leaves mid-round is taken off the re-hydrate barrier rather than waited for
     * (HIL-436): it cannot answer with a fiction, and whatever starts in its place opens the
     * database that is already in place. Waiting would cost the initiator the full deadline and
     * then close the node over a process that no longer exists.
     *
     * What its agents owned in RT is given back here for the same reason (HIL-586): the release
     * a stopping agent sends never comes from a process that died, and an ownership claim left
     * behind would have this node refusing replicas for a collection nobody here writes.
     *
     * The agents themselves are forgotten last, for the third time that same reason: no stop
     * report is coming, so without it the roster keeps them alive on a client that is gone, and
     * an agent the roster claims is one the master will not restart when it is next addressed.
     * Last, because the two releases above find their work by walking the roster for this
     * worker - emptying it first would leave them nothing to hand back. The guard is the one the
     * log line uses: an index of zero is a client that never registered, and every real worker
     * counts from one, so an unregistered client must not match agents by a zero it shares
     * with nobody.
     */
    protected function onClose(): void
    {
        // Log worker disconnection on daemon side
        if ($this->workerIndex > 0) {
            $workerType = $this->isMonopolistic ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR;
            Logger::debug("Worker #{$this->workerIndex} disconnected [type={$workerType}]");
        }

        $this->agentManager->dropReHydrateParticipant(ReHydrateRound::workerParticipant($this->workerIndex));
        $this->agentManager->releaseRtSourcesOfWorker($this->workerIndex, $this->isMonopolistic);
        $this->agentManager->releaseReaderInterestOfWorker($this->workerIndex);

        if ($this->workerIndex > 0) {
            $lost = $this->agentManager->forgetAgentsOfWorker($this->workerIndex, $this->isMonopolistic);
            if ($lost !== []) {
                Logger::info(
                    "Worker #{$this->workerIndex} died hosting " . count($lost) . ' agent(s): '
                    . implode(', ', $lost),
                );
            }
        }
    }
}
