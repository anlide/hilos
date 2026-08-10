<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Constants\WorkerConstants;
use Hilos\Hilos;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\SocketException;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\ProtectedModeReadyDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeDisableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeEnableDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
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
     * @throws InvalidArgumentException When message JSON or worker message type is invalid
     * @throws AgentDaemonCreationFailedException When agent creation fails during message handling
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
     * @throws InvalidArgumentException When message JSON or worker message type is invalid
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
            $workerDTO instanceof WorkerAgentStartedDTO => $this->handleAgentStartedMessage($workerDTO),
            $workerDTO instanceof WorkerAgentStoppedDTO => $this->handleAgentStoppedMessage($workerDTO),
            $workerDTO instanceof WorkerAgentMessageDTO => $this->handleAgentMessageMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncCreatedMessageDTO => $this->handleWorkerDbSyncCreatedMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncUpdatedMessageDTO => $this->handleWorkerDbSyncUpdatedMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncDeletedMessageDTO => $this->handleWorkerDbSyncDeletedMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncClearedMessageDTO => $this->handleWorkerDbSyncClearedMessage($workerDTO),
            $workerDTO instanceof WorkerDbReHydrateMessageDTO => $this->handleWorkerDbReHydrateMessage(),
            $workerDTO instanceof WorkerRtSyncCreatedMessageDTO => $this->handleWorkerRtSyncCreatedMessage($workerDTO),
            $workerDTO instanceof WorkerRtSyncUpdatedMessageDTO => $this->handleWorkerRtSyncUpdatedMessage($workerDTO),
            $workerDTO instanceof WorkerRtSyncDeletedMessageDTO => $this->handleWorkerRtSyncDeletedMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeEnableDTO => $this->handleProtectedModeEnableMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeDisableDTO => $this->handleProtectedModeDisableMessage($workerDTO),
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
     * Handle agent started message
     *
     * @param WorkerAgentStartedDTO $dto DTO with agent started data
     * @throws AgentDaemonCreationFailedException If agent creation fails
     */
    private function handleAgentStartedMessage(WorkerAgentStartedDTO $dto): void
    {
        $this->agentManager->handleAgentStarted($dto);
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
     * The frame carries no payload, so nothing is unwrapped here: the daemon only has to learn
     * that the database was replaced and pass the fact on, which is why the DTO is not even
     * taken as a parameter.
     */
    private function handleWorkerDbReHydrateMessage(): void
    {
        $this->agentManager->handleWorkerDbReHydrate();
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
     */
    public function sendAgentStart(string $agentType, ?string $agentIndex = null): void
    {
        // external-boundary: the neutral element of the agent id — a singleton is the bare type
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        Logger::debug("Sending agent_start signal to worker [agentId={$agentId}] [agentType={$agentType}] [agentIndex=" . ($agentIndex ?? 'null') . "] [workerIndex={$this->workerIndex}]");

        $dto = new AgentStartDTO(
            agentId: $agentId,
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
     */
    protected function onClose(): void
    {
        // Log worker disconnection on daemon side
        if ($this->workerIndex > 0) {
            $workerType = $this->isMonopolistic ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR;
            Logger::debug("Worker #{$this->workerIndex} disconnected [type={$workerType}]");
        }
    }
}
