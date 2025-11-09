<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\SocketException;
use Hilos\Exception\Worker\AgentDaemonCreationFailedException;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Utils\DTO\Worker\AgentStartDTO;
use Hilos\Utils\DTO\Worker\AgentStopDTO;
use Hilos\Utils\DTO\Worker\WorkerRegisterDTO;
use Hilos\Utils\DTO\Worker\WorkerRegisteredDTO;
use Hilos\Utils\DTO\Worker\WorkerAgentStartedDTO;
use Hilos\Utils\DTO\Worker\WorkerAgentStoppedDTO;
use Hilos\Utils\DTO\Worker\WorkerAgentMessageDTO;
use Hilos\Logging\Logger\Logger;

/**
 * WorkerClient - Represents a single worker connection
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
     * WorkerClient constructor
     *
     * @param resource $socket Client socket
     * @param SignalRouter $signalRouter Signal router instance
     * @param AgentManagerDaemon $agentManager Agent manager daemon instance
     */
    public function __construct($socket, SignalRouter $signalRouter, AgentManagerDaemon $agentManager)
    {
        parent::__construct($socket, $signalRouter);
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
     * Process read buffer - check for complete messages
     *
     * Safely parses JSON messages by tracking bracket depth.
     * Handles JSON objects that may contain newlines in strings.
     * @throws AgentDaemonCreationFailedException
     * @throws SocketException
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
     * Process message from worker
     *
     * @param string $message Message data
     * @throws AgentDaemonCreationFailedException
     */
    private function processMessage(string $message): void
    {
        // Parse JSON message
        $data = json_decode($message, true);
        if ($data === null) {
            return;
        }

        // Handle different message types
        $type = $data['type'] ?? 'unknown';
        switch ($type) {
            case WorkerRegisterDTO::MESSAGE_TYPE:
                $this->handleWorkerRegisterMessage($data);
                break;
            case WorkerAgentStartedDTO::MESSAGE_TYPE:
                $this->handleAgentStartedMessage($data);
                break;
            case WorkerAgentStoppedDTO::MESSAGE_TYPE:
                $this->handleAgentStoppedMessage($data);
                break;
            case WorkerAgentMessageDTO::MESSAGE_TYPE:
                $this->handleAgentMessageMessage($data);
                break;
            default:
                // Unknown message type
                break;
        }
    }

    /**
     * Handle worker register message
     *
     * @param array $data Message data
     */
    private function handleWorkerRegisterMessage(array $data): void
    {
        $this->handleWorkerRegister($data);
    }

    /**
     * Handle agent started message
     *
     * @param array $data Message data
     * @throws AgentDaemonCreationFailedException
     */
    private function handleAgentStartedMessage(array $data): void
    {
        try {
            $dto = WorkerAgentStartedDTO::fromArray($data);
        } catch (\Throwable $e) {
            Logger::error("Failed to parse agent_started DTO: " . $e->getMessage());
            return;
        }

        $this->agentManager->handleAgentStarted($this, $dto);
    }

    /**
     * Handle agent stopped message
     *
     * @param array $data Message data
     */
    private function handleAgentStoppedMessage(array $data): void
    {
        try {
            $dto = WorkerAgentStoppedDTO::fromArray($data);
        } catch (\Throwable $e) {
            Logger::error("Failed to parse agent_stopped DTO: " . $e->getMessage());
            return;
        }

        $this->agentManager->handleAgentStopped($this, $dto);
    }

    /**
     * Handle agent message
     *
     * @param array $data Message data
     */
    private function handleAgentMessageMessage(array $data): void
    {
        try {
            $dto = WorkerAgentMessageDTO::fromArray($data);
        } catch (\Throwable $e) {
            Logger::error("Failed to parse agent_message DTO: " . $e->getMessage());
            return;
        }

        $this->agentManager->handleAgentMessage($this, $dto);
    }

    /**
     * Handle worker registration message
     *
     * @param array $data Message data
     */
    private function handleWorkerRegister(array $data): void
    {
        try {
            $dto = WorkerRegisterDTO::fromArray($data);
        } catch (\Throwable $e) {
            Logger::error("Failed to parse worker_register DTO: " . $e->getMessage());
            return;
        }

        $this->setWorkerIndex($dto->workerIndex);
        $this->setIsMonopolistic($dto->monopolistic);
        $this->isRegistered = true;

        // Log worker registration on daemon side
        $workerType = $dto->monopolistic ? 'monopolistic' : 'regular';
        Logger::debug("Worker #{$dto->workerIndex} registered [type={$workerType}]");

        // Send registration confirmation to worker using DTO
        $responseDto = new WorkerRegisteredDTO(
            workerIndex: $dto->workerIndex,
            monopolistic: $dto->monopolistic,
        );
        $this->send($responseDto->toJson());
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
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        Logger::debug("Sending agent_start signal to worker [agentId={$agentId}] [agentType={$agentType}] [agentIndex=" . ($agentIndex ?? 'null') . "] [workerIndex={$this->workerIndex}]");

        $dto = new AgentStartDTO(
            agentType: $agentType,
            agentIndex: $agentIndex,
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
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        Logger::debug("Sending agent_stop signal to worker [agentId={$agentId}] [workerIndex={$this->workerIndex}]");

        $dto = new AgentStopDTO(
            agentType: $agentType,
            agentIndex: $agentIndex,
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
     * Called when socket connection is successfully closed
     */
    protected function onClose(): void
    {
        // Log worker disconnection on daemon side
        if ($this->workerIndex > 0) {
            $workerType = $this->isMonopolistic ? 'monopolistic' : 'regular';
            Logger::debug("Worker #{$this->workerIndex} disconnected [type={$workerType}]");
        }
    }
}
