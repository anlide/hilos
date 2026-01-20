<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Router;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\GroupConstants;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Utils\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
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
                'agentType' => AgentType::SESSION,
                'agentIndex' => 'clientId',
                'params' => [],
            ],
            PageConstants::USER => [
                'agentType' => AgentType::SESSION,
                'agentIndex' => 'clientId',
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
                'agentType' => AgentType::SESSION,
                'agentIndex' => 'clientId',
                'params' => [],
            ],
        ];

        // Signals configuration - defines signal routing rules
        $signals = [
            SignalSource::DAEMON => [
                SignalTypeConstants::SYSTEM => AgentType::CHAT,
                SignalTypeConstants::CRON => AgentType::CHAT,
            ],
            SignalSource::WEBSOCKET => [
                // Page subscription signals - routing to CHAT agent
                SignalTypeConstants::PAGE_SUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::PAGE_UNSUBSCRIBE => AgentType::CHAT,
                SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION => AgentType::CHAT,
                // Group subscription signals - routing to SESSION agent
                SignalTypeConstants::GROUP_SUBSCRIBE => AgentType::SESSION,
                SignalTypeConstants::GROUP_UNSUBSCRIBE => AgentType::SESSION,
                SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => AgentType::SESSION,
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
}
