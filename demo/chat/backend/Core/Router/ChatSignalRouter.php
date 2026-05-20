<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * ChatSignalRouter - Signal router for chat demo.
 *
 * Declares chat service-signal defaults and dynamic routes that depend on signal payload.
 * Page subscription, page actions, page-owned signals, group subscription ownership,
 * and agent-owned agent signals are resolved by framework SignalRouter from project topology.
 */
final class ChatSignalRouter extends SignalRouter
{
    /**
     * Returns chat project facade for topology registry reads.
     *
     * @return class-string<Hilos> Chat project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns the chat owner for subscriptions to unregistered pages.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultPageSubscriptionAgentType(): ?string
    {
        return AgentType::CHAT;
    }

    /**
     * Returns the chat owner for subscriptions to unregistered groups.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultGroupSubscriptionAgentType(): ?string
    {
        return AgentType::CHAT;
    }

    /**
     * Returns chat agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::CHAT,
            AgentType::LIBRARY,
            AgentType::CHAT_CONTEXT_ANALYZER,
            AgentType::MODERATOR,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_GUARDIAN,
            AgentType::HILOS_ANALYTICS,
            AgentType::HILOS_LOGS,
        ];
    }

    /**
     * Returns the chat owner for generic daemon cron signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultDaemonCronAgentType(): ?string
    {
        return AgentType::CHAT;
    }

    /**
     * Returns the chat owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::CHAT;
    }
}
