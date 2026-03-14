<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * ChatAgentDaemon - Daemon proxy for ChatAgent.
 *
 * Simple proxy class for chat agent on daemon side.
 * Handles routing between WebSocket clients and ChatAgent in worker.
 */
class ChatAgentDaemon extends AbstractAgentDaemon
{
    /** @var string Agent type */
    private const string AGENT_TYPE = 'chat';

    /**
     * Creates daemon proxy for ChatAgent.
     */
    public function __construct()
    {
        Logger::debug("ChatAgentDaemon created [type=" . self::AGENT_TYPE . "]");
    }

    /**
     * Get agent type.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    /**
     * Get agent index.
     *
     * Chat agent has no index (global singleton).
     *
     * @return ?string Agent index (null for global chat agent)
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Check if agent requires monopolistic worker process.
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
     * Handle message from worker agent.
     *
     * ChatAgent handles WebSocket routing via its own sendToUser/sendToAllUsers.
     * Agent signals (BOT_JOINED, MODERATION_RESULT, etc.) are processed in onSignalAgent.
     *
     * @param array<string, mixed> $data Message data from worker
     */
    public function handleWorkerMessage(array $data): void
    {
        // No-op: ChatAgent routes to WebSocket clients directly in worker
    }

    /**
     * Handle message from external source (WebSocket, HTTP, etc.).
     *
     * @param array<string, mixed> $data Message data from external source
     * @return ?array<string, mixed> Response data or null
     */
    public function handleExternalMessage(array $data): ?array
    {
        // No-op: WebSocket messages are routed to ChatAgent via framework
        return null;
    }
}
