<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent;

use Demo\WebSocketTest\Utils\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\DTO\MessageFromDaemonDTO;
use Hilos\Core\Agent\DTO\MessageFromUserDTO;
use Hilos\Logging\Logger\AgentLogger;

/**
 * UserAgent - Regular agent for user heartbeat
 *
 * Runs in regular worker process. Sends heartbeat every 5 seconds.
 */
class UserAgent extends AbstractAgent
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::USER;

    /** @var string User ID */
    private string $userId;

    /** @var float Last heartbeat timestamp */
    private float $lastHeartbeat = 0.0;

    /** @var float Heartbeat interval in milliseconds (5 seconds) */
    private const float HEARTBEAT_INTERVAL = 5000.0; // 5 seconds

    /**
     * UserAgent constructor
     *
     * @param string $userId User identifier
     */
    public function __construct(string $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Get agent type
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    /**
     * Get agent index
     *
     * User agent index is the user ID
     *
     * @return ?string Agent index (user ID)
     */
    public function getIndex(): ?string
    {
        return $this->userId;
    }

    /**
     * Get user ID
     *
     * @return string User ID
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Called when agent is started
     */
    public function onStart(): void
    {
        AgentLogger::logStart($this->getId(), $this->getType());
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        AgentLogger::logStop($this->getId(), $this->getType());
    }

    /**
     * Agent-specific tick implementation
     */
    protected function doTick(): void
    {
        $currentTime = microtime(true) * 1000;

        // Send heartbeat every 5 seconds
        if (($currentTime - $this->lastHeartbeat) >= self::HEARTBEAT_INTERVAL) {
            $this->sendHeartbeat();
            $this->lastHeartbeat = $currentTime;
        }
    }

    /**
     * Handle message from daemon
     *
     * @param MessageFromDaemonDTO $message Message from daemon
     * @return AgentMessageDTOInterface|null Response DTO
     */
    public function handleMessageFromDaemon(MessageFromDaemonDTO $message): ?AgentMessageDTOInterface
    {
        // User agent only responds to specific requests
        // TODO: Implement proper message handling with DTO
        return null;
    }

    /**
     * Handle message from user
     *
     * @param MessageFromUserDTO $message Message from user
     * @return AgentMessageDTOInterface|null Response DTO
     */
    public function handleMessageFromUser(MessageFromUserDTO $message): ?AgentMessageDTOInterface
    {
        // payload is array in MessageFromUserDTO, convert to string for logging
        $payloadStr = json_encode($message->payload, JSON_UNESCAPED_UNICODE);
        AgentLogger::logUserMessage($this->getId(), $message->userId, $payloadStr);
        // User agent can receive messages from users
        // TODO: Implement proper message handling with DTO
        return null;
    }

    /**
     * Send heartbeat to daemon
     */
    private function sendHeartbeat(): void
    {
        // TODO: Use proper DTO for heartbeat
        // For now, this will be implemented when agent manager is ready
    }
}

