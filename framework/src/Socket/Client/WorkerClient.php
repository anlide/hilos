<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Utils\DTO\AgentMessageDTO;
use Hilos\Utils\Helpers\TimeHelper;

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
            case 'worker_register':
                $this->handleWorkerRegister($data);
                break;
            case 'heartbeat':
                $this->handleHeartbeat($data);
                break;
            case 'status':
                $this->handleStatus($data);
                break;
            case 'agent_started':
            case 'agent_stopped':
            case 'agent_message':
                $this->processAgentMessage($data);
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
        $workerIndex = $data['workerIndex'] ?? 0;
        $isMonopolistic = $data['monopolistic'] ?? false;

        $this->setWorkerIndex($workerIndex);
        $this->setIsMonopolistic($isMonopolistic);
        $this->isRegistered = true;

        // Log worker registration on daemon side
        $workerType = $isMonopolistic ? 'monopolistic' : 'regular';
        $timestamp = TimeHelper::getTimestampWithMs();
        echo "[{$timestamp}] Worker #{$workerIndex} registered [daemon side] [type={$workerType}]\n";

        // Send registration confirmation to worker
        $response = json_encode([
            'type' => 'worker_registered',
            'workerIndex' => $workerIndex,
            'monopolistic' => $isMonopolistic,
        ]);
        $this->send($response);

        // Notify registration handler
        if ($this->workerRegistrationHandler !== null) {
            ($this->workerRegistrationHandler)($this);
        }
    }

    /**
     * Handle heartbeat message
     *
     * @param array $data Message data
     */
    private function handleHeartbeat(array $data): void
    {
        // Send heartbeat response
        $response = json_encode(['type' => 'heartbeat_ack', 'timestamp' => time()]);
        $this->send($response);

        // Log heartbeat on daemon side (optional, can be verbose - comment out if too noisy)
        // $timestamp = TimeHelper::getTimestampWithMs();
        // error_log("[{$timestamp}] Worker #{$this->workerIndex} heartbeat [daemon side]");
    }

    /**
     * Handle status message
     *
     * @param array $data Message data
     */
    private function handleStatus(array $data): void
    {
        // Process worker status
        // TODO: Store worker status
    }

    /** @var callable|null Callback for processing agent lifecycle messages */
    private $agentMessageHandler = null;

    /** @var callable|null Callback for worker registration */
    private $workerRegistrationHandler = null;

    /**
     * Set agent message handler callback
     *
     * @param callable $handler Callback function(WorkerClient $client, array $data)
     */
    public function setAgentMessageHandler(callable $handler): void
    {
        $this->agentMessageHandler = $handler;
    }

    /**
     * Set worker registration handler callback
     *
     * @param callable $handler Callback function(WorkerClient $client): void
     */
    public function setWorkerRegistrationHandler(callable $handler): void
    {
        $this->workerRegistrationHandler = $handler;
    }

    /**
     * Process agent lifecycle messages
     *
     * @param array $data Message data from worker
     */
    public function processAgentMessage(array $data): void
    {
        $type = $data['type'] ?? '';
        
        // Forward agent lifecycle messages to handler if set
        if ($this->agentMessageHandler !== null && in_array($type, ['agent_started', 'agent_stopped', 'agent_message'])) {
            ($this->agentMessageHandler)($this, $data);
        }
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
        $timestamp = TimeHelper::getTimestampWithMs();
        echo "[{$timestamp}] Sending agent_start signal to worker [daemon side] [agentId={$agentId}] [agentType={$agentType}] [agentIndex=" . ($agentIndex ?? 'null') . "] [workerIndex={$this->workerIndex}]\n";
        
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
        $timestamp = TimeHelper::getTimestampWithMs();
        echo "[{$timestamp}] Sending agent_stop signal to worker [daemon side] [agentId={$agentId}] [workerIndex={$this->workerIndex}]\n";
        
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
     * Called when socket connection is successfully closed
     */
    protected function onClose(): void
    {
        // Log worker disconnection on daemon side
        if ($this->workerIndex > 0) {
            $workerType = $this->isMonopolistic ? 'monopolistic' : 'regular';
            $timestamp = TimeHelper::getTimestampWithMs();
            echo "[{$timestamp}] Worker #{$this->workerIndex} disconnected [daemon side] [type={$workerType}]\n";
        }
    }
}

