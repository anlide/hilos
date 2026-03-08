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
 * Routes WebSocket signals to chat agent.
 * Supports page and group subscriptions for routing.
 */
class ChatSignalRouter extends SignalRouter
{
    /**
     * Constructor
     *
     * Initializes chat signal router with routing configuration.
     */
    public function __construct()
    {
        parent::__construct();

        // Pages configuration - defines available pages and their routing
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
        ];

        // Groups configuration - defines available groups and their routing
        // Groups can be added dynamically or through config
        $groups = [
            GroupConstants::SESSION => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
        ];

        // Signals configuration - defines signal routing rules
        $signals = [
            SignalSource::DAEMON => [
                SignalTypeConstants::SYSTEM => [
                    AgentType::CHAT,
                    AgentType::CHAT_CONTEXT_ANALYZER,
                    AgentType::MODERATOR,
                    AgentType::GUARDIAN_OPS,
                    AgentType::CHAT_SITUATION_GUARDIAN,
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
                // Page subscription signals - routing to CHAT agent
                SignalTypeConstants::HANDSHAKE => AgentType::CHAT,
                SignalTypeConstants::CONNECTION_CLOSE => AgentType::CHAT,
                SignalTypeConstants::PAGE_SUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::PAGE_UNSUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION => AgentType::CHAT,
                // Group subscription signals - routing to CHAT agent
                SignalTypeConstants::GROUP_SUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::GROUP_UNSUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => AgentType::CHAT,
                // User`s action signal - routing to CHAT agent
                SignalTypeConstants::ACTION => AgentType::CHAT,
                // Cron`s action signal - routing to CHAT agent
                SignalTypeConstants::CRON => AgentType::CHAT,
            ],
        ];

        $actions = [
            ChatSignalConstants::MESSAGE => AgentType::CHAT,
            ChatSignalConstants::FILE => AgentType::CHAT,
            ChatSignalConstants::RENAME => AgentType::CHAT,
        ];

        // Signal routing configuration
        $this->config = [
            'pages' => $pages,
            'groups' => $groups,
            'signals' => $signals,
            'actions' => $actions,
        ];
    }

    /**
     * Get destinations for signal
     *
     * BOT_AGENT_START and BOT_AGENT_RELOAD route to specific BotAgent.
     * DB_SYNC for bots collection also routes to BotAgent (subscription by rowKey).
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
