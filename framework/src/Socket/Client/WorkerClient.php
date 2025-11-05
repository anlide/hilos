<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Utils\DTO\Worker\AgentMessageDTO;
use Hilos\Utils\DTO\Worker\WorkerRegisterDTO;
use Hilos\Utils\DTO\Worker\WorkerRegisteredDTO;
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

    /** @var array<int, array> Queue of agent messages waiting to be processed [['type' => string, 'data' => array], ...] */
    private array $pendingAgentMessages = [];

    /** @var float Connection time (microtime) */
    private float $connectTime;

    /** @var float Registration timeout in seconds */
    private float $registrationTimeout = 10.0;

    /**
     * WorkerClient constructor
     *
     * @param resource $socket Client socket
     * @param SignalRouter $signalRouter Signal router instance
     */
    public function __construct($socket, SignalRouter $signalRouter)
    {
        parent::__construct($socket, $signalRouter);
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
     */
    protected function processReadBuffer(): void
    {
        // Check if we have complete message (ends with \n)
        while (($pos = strpos($this->readBuffer, "\n")) !== false) {
            $message = substr($this->readBuffer, 0, $pos);
            $this->readBuffer = substr($this->readBuffer, $pos + 1);
            $this->processMessage($message);
        }
    }

    /**
     * Process message from worker
     *
     * @param string $message Message data
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
                $this->handleWorkerRegister($data);
                break;
            case 'agent_started':
            case 'agent_stopped':
            case 'agent_message':
                // Queue agent messages for WorkerServer to process
                $this->pendingAgentMessages[] = $data;
                break;
            default:
                // Unknown message type
                break;
        }
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
        Logger::info("Worker #{$dto->workerIndex} registered [daemon side] [type={$workerType}]");

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
     * @param string $agentId Agent ID
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     */
    public function sendAgentStart(string $agentId, string $agentType, ?string $agentIndex = null): void
    {
        Logger::info("Sending agent_start signal to worker [daemon side] [agentId={$agentId}] [agentType={$agentType}] [agentIndex=" . ($agentIndex ?? 'null') . "] [workerIndex={$this->workerIndex}]");

        $dto = new AgentMessageDTO(
            type: AgentMessageDTO::TYPE_AGENT_START,
            agentId: $agentId,
            agentType: $agentType,
            agentIndex: $agentIndex,
        );

        $this->send($dto->toJson());
    }

    /**
     * Send agent_stop signal to worker
     *
     * @param string $agentId Agent ID
     */
    public function sendAgentStop(string $agentId): void
    {
        Logger::info("Sending agent_stop signal to worker [daemon side] [agentId={$agentId}] [workerIndex={$this->workerIndex}]");

        // Extract agent type from agentId (format: "type" or "type:index")
        $parts = explode(':', $agentId, 2);
        $agentType = $parts[0];
        $agentIndex = $parts[1] ?? null;

        $dto = new AgentMessageDTO(
            type: AgentMessageDTO::TYPE_AGENT_STOP,
            agentId: $agentId,
            agentType: $agentType,
            agentIndex: $agentIndex,
        );

        $this->send($dto->toJson());
    }

    /**
     * Get and clear pending agent messages
     *
     * Returns all queued agent messages and clears the queue.
     * Used by WorkerServer to process agent lifecycle events.
     *
     * @return array Pending agent messages [['type' => string, ...], ...]
     */
    public function getAndClearPendingAgentMessages(): array
    {
        $messages = $this->pendingAgentMessages;
        $this->pendingAgentMessages = [];
        return $messages;
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
            Logger::info("Worker #{$this->workerIndex} disconnected [daemon side] [type={$workerType}]");
        }
    }
}
