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

        $pages = [];
        foreach (Hilos::PAGE_ROUTES as $page => $agentType) {
            $pages[$page] = [
                'agentType' => $agentType,
                'agentIndex' => null,
                'params' => [],
            ];
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
                SignalTypeConstants::AGENT_SIGNAL => [
                    ChatSignalConstants::MODERATION_RESULT => AgentType::CHAT,
                    ChatSignalConstants::RENAME_MODERATION_RESULT => AgentType::CHAT,
                    ChatSignalConstants::BOT_MESSAGE => AgentType::CHAT,
                ],
            ],
            SignalSource::WEBSOCKET => [
                SignalTypeConstants::HANDSHAKE => AgentType::CHAT,
                SignalTypeConstants::CONNECTION_CLOSE => AgentType::CHAT,
                SignalTypeConstants::FRAME_BINARY => AgentType::CHAT,
                SignalTypeConstants::GROUP_SUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::GROUP_UNSUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => AgentType::CHAT,
                SignalTypeConstants::ACTION => AgentType::CHAT,
                SignalTypeConstants::CRON => AgentType::CHAT,
            ],
        ];

        $pageSubscriptionRouting = [
            'default' => AgentType::CHAT,
            'pages' => Hilos::PAGE_ROUTES,
        ];

        $actions = [
            ChatSignalConstants::MESSAGE => AgentType::CHAT,
            ChatSignalConstants::FILE_UPLOAD_INIT => AgentType::CHAT,
            ChatSignalConstants::RENAME => AgentType::CHAT,
            ChatSignalConstants::USER_UPDATE => AgentType::CHAT,
            ChatSignalConstants::HILOS_USER_UPDATE => AgentType::CHAT,
            ChatSignalConstants::BOT_CREATE => AgentType::LIBRARY,
            ChatSignalConstants::BOT_UPDATE => AgentType::LIBRARY,
            ChatSignalConstants::BOT_DELETE => AgentType::LIBRARY,
            ChatSignalConstants::MODERATOR_PIECE_CREATE => AgentType::LIBRARY,
            ChatSignalConstants::MODERATOR_PIECE_UPDATE => AgentType::LIBRARY,
            ChatSignalConstants::MODERATOR_PIECE_DELETE => AgentType::LIBRARY,
            ChatSignalConstants::SETTING_ADD => AgentType::HILOS_INDEX,
            ChatSignalConstants::SETTING_UPDATE => AgentType::HILOS_INDEX,
            ChatSignalConstants::SETTING_DELETE => AgentType::HILOS_INDEX,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_START => AgentType::HILOS_GUARDIAN,
            ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => AgentType::HILOS_GUARDIAN,
        ];

        $this->config = [
            'pages' => $pages,
            'groups' => $groups,
            'signals' => $signals,
            'actions' => $actions,
            'page_subscription_routing' => $pageSubscriptionRouting,
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
