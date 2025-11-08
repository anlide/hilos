<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Router;

use Demo\WebSocketTest\Utils\Constants\AgentType;
use Demo\WebSocketTest\Utils\Constants\PageConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Utils\Constants\SignalConstants;

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
            PageConstants::CHAT => [
                'agentType' => AgentType::CHAT,
                'agentIndex' => null,
                'params' => [],
            ],
        ];

        // Groups configuration - defines available groups and their routing
        // Groups can be added dynamically or through config
        $groups = [
            // Example: 'room1' => [
            //     'agentType' => AgentType::CHAT,
            //     'agentIndex' => 'room1',
            //     'params' => [],
            // ],
        ];

        // Signal routing configuration
        $this->config = [
            'pages' => $pages,
            'groups' => $groups,
            'signals' => [
                SignalSource::SOURCE_WEBSOCKET => [
                    // Legacy signals - direct routing
                    SignalConstants::SIGNAL_FRAME => [
                        'routeBy' => 'direct',
                        'agentType' => AgentType::CHAT,
                        'agentIndex' => null,
                    ],
                    SignalConstants::SIGNAL_FRAME_BINARY => [
                        'routeBy' => 'direct',
                        'agentType' => AgentType::CHAT,
                        'agentIndex' => null,
                    ],
                    SignalConstants::SIGNAL_HANDSHAKE => [
                        'routeBy' => 'direct',
                        'agentType' => AgentType::CHAT,
                        'agentIndex' => null,
                    ],
                    SignalConstants::SIGNAL_CLOSE => [
                        'routeBy' => 'direct',
                        'agentType' => AgentType::CHAT,
                        'agentIndex' => null,
                    ],
                    // Page subscription signals - routing by page
                    SignalConstants::SIGNAL_SUBSCRIBE_PAGE => [
                        'routeBy' => 'page',
                    ],
                    SignalConstants::SIGNAL_UNSUBSCRIBE_PAGE => [
                        'routeBy' => 'page',
                    ],
                    SignalConstants::SIGNAL_UPDATE_SUBSCRIPTION_PAGE => [
                        'routeBy' => 'page',
                    ],
                    SignalConstants::SIGNAL_ACTION => [
                        'routeBy' => 'page',
                    ],
                    // Group subscription signals - routing by group
                    SignalConstants::SIGNAL_SUBSCRIBE_GROUP => [
                        'routeBy' => 'group',
                    ],
                    SignalConstants::SIGNAL_UNSUBSCRIBE_GROUP => [
                        'routeBy' => 'group',
                    ],
                    SignalConstants::SIGNAL_UPDATE_SUBSCRIPTION_GROUP => [
                        'routeBy' => 'group',
                    ],
                ],
            ],
        ];
    }
}

