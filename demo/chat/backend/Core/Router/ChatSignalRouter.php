<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\GroupConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;

/**
 * ChatSignalRouter - Signal router for chat demo
 *
 * Defines declarative routing rules for all signal types in the chat demo project.
 * Routes signals by source and type to the appropriate agent.
 *
 * Page subscription signals (PAGE_SUBSCRIBE, PAGE_UNSUBSCRIBE, PAGE_UPDATE_SUBSCRIPTION)
 * are routed per-page via 'page_subscription_routing' config, with a default fallback.
 */
class ChatSignalRouter extends SignalRouter
{
    public function __construct()
    {
        parent::__construct();

        $pages = [
            PageConstants::MAIN => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::PROFILE => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::USER => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::BOT => [
                'agentType' => AgentType::BOT,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::MODERATOR => [
                'agentType' => AgentType::MODERATOR,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::ADMIN => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::ADMIN_USERS => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::ADMIN_MODERATOR => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::ADMIN_BOTS => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::HILOS_DASHBOARD => [
                'agentType' => AgentType::HILOS_INDEX,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::HILOS_SETTINGS => [
                'agentType' => AgentType::HILOS_INDEX,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::HILOS_I18N => [
                'agentType' => AgentType::HILOS_INDEX,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::HILOS_GUARDIAN => [
                'agentType' => AgentType::HILOS_GUARDIAN,
                'agentIndex' => null,
                'params' => [],
            ],
            PageConstants::HILOS_ANALYTICS => [
                'agentType' => AgentType::HILOS_ANALYTICS,
                'agentIndex' => null,
                'params' => [],
            ],
        ];

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
                    AgentType::CHAT_CONTEXT_ANALYZER,
                    AgentType::MODERATOR,
                    AgentType::GUARDIAN_OPS,
                    AgentType::CHAT_SITUATION_GUARDIAN,
                    AgentType::HILOS_INDEX,
                    AgentType::HILOS_GUARDIAN,
                    AgentType::HILOS_ANALYTICS,
                ],
                SignalTypeConstants::CRON => AgentType::CHAT,
            ],
            SignalSource::AGENT => [
                SignalTypeConstants::AGENT_SIGNAL => [
                    ChatSignalConstants::MODERATE_REQUEST => AgentType::MODERATOR,
                    ChatSignalConstants::MODERATION_RESULT => AgentType::CHAT,
                    ChatSignalConstants::MODERATE_BOT_REQUEST => AgentType::MODERATOR,
                    ChatSignalConstants::MODERATION_BOT_RESULT => AgentType::CHAT,
                    ChatSignalConstants::BOT_JOINED => AgentType::CHAT,
                    ChatSignalConstants::BOT_LEFT => AgentType::CHAT,
                    ChatSignalConstants::GUARDIAN_REPORT => AgentType::CHAT,
                ],
            ],
            SignalSource::WEBSOCKET => [
                SignalTypeConstants::HANDSHAKE => AgentType::CHAT,
                SignalTypeConstants::CONNECTION_CLOSE => AgentType::CHAT,
                SignalTypeConstants::GROUP_SUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::GROUP_UNSUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => AgentType::CHAT,
                SignalTypeConstants::ACTION => AgentType::CHAT,
                SignalTypeConstants::CRON => AgentType::CHAT,
            ],
        ];

        $pageSubscriptionRouting = [
            'default' => AgentType::CHAT,
            'pages' => [
                PageConstants::HILOS_DASHBOARD => AgentType::HILOS_INDEX,
                PageConstants::HILOS_SETTINGS => AgentType::HILOS_INDEX,
                PageConstants::HILOS_I18N => AgentType::HILOS_INDEX,
                PageConstants::HILOS_GUARDIAN => AgentType::HILOS_GUARDIAN,
                PageConstants::HILOS_ANALYTICS => AgentType::HILOS_ANALYTICS,
            ],
        ];

        $actions = [
            ChatSignalConstants::MESSAGE => AgentType::CHAT,
            ChatSignalConstants::FILE => AgentType::CHAT,
            ChatSignalConstants::RENAME => AgentType::CHAT,
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
     * @return array<int, array<string, mixed>> Array of destinations
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
     * Extract bot ID from agent signal data
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

        $payload = $data->data;
        if (!$payload instanceof BotAgentSignalData) {
            return 0;
        }

        return $payload->botId;
    }
}
