<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent;

use Demo\WebSocketTest\Utils\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Utils\DTO\Agent\AgentMessageDTOInterface;
use Hilos\Utils\DTO\Agent\MessageFromDaemonDTO;
use Hilos\Utils\DTO\Agent\MessageFromUserDTO;
use Hilos\Logging\Logger\Logger;

/**
 * UserAgent - Regular agent for user management
 *
 * Runs in regular worker process.
 */
class UserAgent extends AbstractAgent
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::USER;

    /** @var string User ID */
    private string $userId;

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
        Logger::logAgentStart($this->getId(), $this->getType());
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        Logger::logAgentStop($this->getId(), $this->getType());
    }

    /**
     * Agent-specific tick implementation
     */
    protected function doTick(): void
    {
        // TODO: Add user-specific logic here
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
        Logger::logAgentUserMessage($this->getId(), $message->userId, $payloadStr);
        // User agent can receive messages from users
        // TODO: Implement proper message handling with DTO
        return null;
    }
}

