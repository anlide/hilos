<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Socket\Client\Interface\WorkerClientInterface;

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

        // Log worker registration on daemon side
        $workerType = $isMonopolistic ? 'monopolistic' : 'regular';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[{$timestamp}] Worker #{$workerIndex} registered [daemon side] [type={$workerType}]");

        // Send registration confirmation to worker
        $response = json_encode([
            'type' => 'worker_registered',
            'workerIndex' => $workerIndex,
            'monopolistic' => $isMonopolistic,
        ]);
        $this->send($response);
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
        // $timestamp = date('Y-m-d H:i:s');
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
     * Send agent_start message to worker
     *
     * @param string $agentId Agent ID
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     */
    public function sendAgentStart(string $agentId, string $agentType, ?string $agentIndex = null): void
    {
        $timestamp = date('Y-m-d H:i:s');
        error_log("[{$timestamp}] Sending agent_start to worker [daemon side] [agentId={$agentId}] [agentType={$agentType}] [agentIndex=" . ($agentIndex ?? 'null') . "] [workerIndex={$this->workerIndex}]");
        
        $message = [
            'type' => 'agent_start',
            'agentId' => $agentId,
            'agentType' => $agentType,
        ];
        
        if ($agentIndex !== null) {
            $message['agentIndex'] = $agentIndex;
        }
        
        $this->send(json_encode($message));
    }

    /**
     * Send agent_stop message to worker
     *
     * @param string $agentId Agent ID
     */
    public function sendAgentStop(string $agentId): void
    {
        $timestamp = date('Y-m-d H:i:s');
        error_log("[{$timestamp}] Sending agent_stop to worker [daemon side] [agentId={$agentId}] [workerIndex={$this->workerIndex}]");
        
        $message = [
            'type' => 'agent_stop',
            'agentId' => $agentId,
        ];
        
        $this->send(json_encode($message));
    }

    /**
     * Called when socket connection is successfully closed
     */
    protected function onClose(): void
    {
        // Log worker disconnection on daemon side
        if ($this->workerIndex > 0) {
            $workerType = $this->isMonopolistic ? 'monopolistic' : 'regular';
            $timestamp = date('Y-m-d H:i:s');
            error_log("[{$timestamp}] Worker #{$this->workerIndex} disconnected [daemon side] [type={$workerType}]");
        }
    }
}

