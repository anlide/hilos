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
    /** @var int Worker ID */
    private int $workerId = 0;

    /**
     * Set worker ID
     *
     * @param int $workerId Worker ID
     */
    public function setWorkerId(int $workerId): void
    {
        $this->workerId = $workerId;
    }

    /**
     * Get worker ID
     *
     * @return int Worker ID
     */
    public function getWorkerId(): int
    {
        return $this->workerId;
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
     * Handle heartbeat message
     *
     * @param array $data Message data
     */
    private function handleHeartbeat(array $data): void
    {
        // Send heartbeat response
        $response = json_encode(['type' => 'heartbeat_ack', 'timestamp' => time()]);
        $this->send($response);
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
     * Called when socket connection is successfully closed
     */
    protected function onClose(): void
    {
        // Worker client cleanup if needed
    }
}

