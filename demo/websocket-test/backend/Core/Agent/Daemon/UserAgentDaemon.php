<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent\Daemon;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Logging\Logger\Logger;

/**
 * UserAgentDaemon - Daemon proxy for UserAgent
 *
 * Simple proxy class for user agent on daemon side.
 * Handles routing between WebSocket clients and UserAgent in worker.
 */
class UserAgentDaemon extends AbstractAgentDaemon
{
    /** @var string Agent type */
    private const string AGENT_TYPE = 'user';

    /** @var string User ID */
    private string $userId;

    /**
     * UserAgentDaemon constructor
     *
     * @param string $userId User identifier
     */
    public function __construct(string $userId)
    {
        $this->userId = $userId;
        Logger::debug("UserAgentDaemon created [type=" . self::AGENT_TYPE . "] [userId={$userId}]");
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
     * Check if agent requires monopolistic worker process
     *
     * User agent does not require monopolistic worker.
     *
     * @return bool False (user agent does not require monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
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
     * Handle message from worker agent
     *
     * @param array $data Message data from worker
     */
    public function handleWorkerMessage(array $data): void
    {
        // TODO: Implement routing to WebSocket client
        // For now, this is a placeholder
    }

    /**
     * Handle message from external source (WebSocket, HTTP, etc.)
     *
     * @param array $data Message data from external source
     * @return array|null Response data
     */
    public function handleExternalMessage(array $data): ?array
    {
        // TODO: Implement routing to worker agent
        // For now, this is a placeholder
        return null;
    }
}
