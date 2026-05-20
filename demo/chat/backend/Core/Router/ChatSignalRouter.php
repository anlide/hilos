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
 * Defines declarative routing rules for all signal types in the chat demo project.
 * Routes signals by source and type to the appropriate agent.
 *
 * Page subscription signals (PAGE_SUBSCRIBE, PAGE_UNSUBSCRIBE, PAGE_UPDATE_SUBSCRIPTION)
 * are routed per-page via 'page_subscription_routing' config, with a default fallback.
 */
final class ChatSignalRouter extends SignalRouter
{
    /**
     * Creates signal router with topology-driven pages and chat-specific static routes.
     */
    public function __construct()
    {
        parent::__construct();

        $pageRoutes = Hilos::getPageRoutes();
        $pageSignalAgentRoutes = Hilos::getPageSignalAgentRoutes();
        $pageOwnedAgentSignalRoutes = $pageSignalAgentRoutes[SignalTypeConstants::AGENT_SIGNAL] ?? [];
        $agentSignalRoutes = Hilos::getAgentSignalRoutes();
        if (is_array($pageOwnedAgentSignalRoutes)) {
            $agentSignalRoutes = array_merge($pageOwnedAgentSignalRoutes, $agentSignalRoutes);
        }

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
            SignalSource::AGENT => [
                SignalTypeConstants::AGENT_SIGNAL => $agentSignalRoutes,
            ],
            SignalSource::WEBSOCKET => $this->buildWebSocketRoutes($pageSignalAgentRoutes),
        ];

        $pageSubscriptionRouting = [
            'default' => AgentType::CHAT,
            'pages' => $pageRoutes,
        ];

        $this->config = [
            'groups' => $groups,
            'signals' => $signals,
            'actions' => Hilos::getActionAgentRoutes(),
            'page_subscription_routing' => $pageSubscriptionRouting,
        ];
    }

    /**
     * Builds WebSocket signal routes from static chat routes and page-owned signal routes.
     *
     * @param array<string, string|array<string, string>> $pageSignalAgentRoutes Page-owned signal owner agent routes
     * @return array<string, string> Signal type to agent type routes
     */
    private function buildWebSocketRoutes(array $pageSignalAgentRoutes): array
    {
        $routes = [
            SignalTypeConstants::HANDSHAKE => AgentType::CHAT,
            SignalTypeConstants::CONNECTION_CLOSE => AgentType::CHAT,
        ];

        $frameBinaryAgentType = $pageSignalAgentRoutes[SignalTypeConstants::FRAME_BINARY] ?? null;
        if (is_string($frameBinaryAgentType) && $frameBinaryAgentType !== '') {
            $routes[SignalTypeConstants::FRAME_BINARY] = $frameBinaryAgentType;
        }

        return $routes + [
            SignalTypeConstants::GROUP_SUBSCRIBE => AgentType::CHAT,
            SignalTypeConstants::GROUP_UNSUBSCRIBE => AgentType::CHAT,
            SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => AgentType::CHAT,
            SignalTypeConstants::CRON => AgentType::CHAT,
        ];
    }

    /**
     * Dynamic routing for signals that require content-based destination resolution.
     *
     * Only use for cases where agentIndex or destination depends on signal payload
     * (e.g. BOT_AGENT_START extracts botId to route to specific BotAgent instance).
     * All static routing is declared in $config above.
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
