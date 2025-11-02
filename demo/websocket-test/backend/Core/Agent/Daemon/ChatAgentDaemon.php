<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent\Daemon;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * ChatAgentDaemon - Daemon proxy for ChatAgent
 *
 * Simple proxy class for chat agent on daemon side.
 * Handles routing between WebSocket clients and ChatAgent in worker.
 */
class ChatAgentDaemon extends AbstractAgentDaemon
{
    /** @var string Agent type */
    private const string AGENT_TYPE = 'chat';

    /**
     * ChatAgentDaemon constructor
     */
    public function __construct()
    {
        $timestamp = TimeHelper::getTimestampWithMs();
        echo "[{$timestamp}] ChatAgentDaemon created [daemon side] [type=" . self::AGENT_TYPE . "]\n";
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
     * Chat agent has no index (global singleton)
     *
     * @return ?string Agent index (null for global chat agent)
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Check if agent requires monopolistic worker process
     *
     * Chat agent requires monopolistic worker.
     *
     * @return bool True (chat agent requires monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }

    /**
     * Handle message from worker agent
     *
     * @param array $data Message data from worker
     */
    public function handleWorkerMessage(array $data): void
    {
        // TODO: Implement routing to WebSocket clients
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

