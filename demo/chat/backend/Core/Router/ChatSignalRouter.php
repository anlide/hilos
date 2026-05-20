<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\GroupConstants;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Hilos;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;

/**
 * ChatSignalRouter - Signal router for chat demo.
 *
 * Defines chat-specific static routing rules for daemon and WebSocket lifecycle
 * signals. Page subscription, page actions, page-owned signals, and agent-owned
 * agent signals are resolved by framework SignalRouter from project topology.
 */
final class ChatSignalRouter extends SignalRouter
{
    /**
     * Creates signal router with chat-specific static routes.
     */
    public function __construct()
    {
        parent::__construct();

        $groups = [
            GroupConstants::SESSION => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
        ];

        $signals = [
            SignalSource::DAEMON => [
                SignalTypeConstants::SYSTEM => [
                    AgentType::CHAT,
                    AgentType::LIBRARY,
                    AgentType::CHAT_CONTEXT_ANALYZER,
                    AgentType::MODERATOR,
                    AgentType::HILOS_INDEX,
                    AgentType::HILOS_GUARDIAN,
                    AgentType::HILOS_ANALYTICS,
                    AgentType::HILOS_LOGS,
                ],
                SignalTypeConstants::CRON => AgentType::CHAT,
            ],
            SignalSource::WEBSOCKET => [
                SignalTypeConstants::HANDSHAKE => AgentType::CHAT,
                SignalTypeConstants::CONNECTION_CLOSE => AgentType::CHAT,
                SignalTypeConstants::GROUP_SUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::GROUP_UNSUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => AgentType::CHAT,
                SignalTypeConstants::CRON => AgentType::CHAT,
            ],
        ];

        $this->config = [
            'groups' => $groups,
            'signals' => $signals,
        ];
    }

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
     * Dynamic routing for signals that require content-based destination resolution.
     *
     * Only use for cases where agentIndex or destination depends on signal payload
     * (e.g. BOT_AGENT_START extracts botId to route to specific BotAgent instance).
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array<string, mixed>> Array of destinations
     */
    public function getDestinations(SignalDTO $signal): array
    {
        $destinations = parent::getDestinations($signal);

        $signalType = $signal->signalType->getType();
        $signalName = $signal->signalName->getName();

        if ($signalType === SignalTypeConstants::AGENT_SIGNAL) {
            if ($signalName === ChatSignalConstants::BOT_AGENT_START) {
                $botId = $this->extractBotIdFromSignal($signal);
                if ($botId > 0) {
                    $destinations[] = [
                        'type' => 'agent',
                        'agentType' => AgentType::BOT,
                        'agentIndex' => (string) $botId,
                    ];
                }
            }
        }

        return $destinations;
    }

    /**
     * Extracts bot ID from agent signal data.
     *
     * @param SignalDTO $signal Signal DTO containing agent signal data
     * @return int Bot ID if found, otherwise 0
     */
    private function extractBotIdFromSignal(SignalDTO $signal): int
    {
        $data = $signal->data;
        if (!$data instanceof AgentSignalData) {
            return 0;
        }

        if (!$data->data instanceof BotAgentSignalData) {
            return 0;
        }

        return $data->data->botId;
    }
}
