<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Router;

use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Utils\Constants\SignalConstants;

/**
 * ChatSignalRouter - Signal router for chat demo
 *
 * Routes WebSocket signals to chat agent.
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
        
        // Signal routing configuration
        // Routes WebSocket signals (frame, handshake, close, frame_binary) to chat agent
        $this->config = [
            SignalSource::SOURCE_WEBSOCKET => [
                SignalConstants::SIGNAL_FRAME => [
                    'agentType' => 'chat',
                    'agentIndex' => null,
                ],
                SignalConstants::SIGNAL_FRAME_BINARY => [
                    'agentType' => 'chat',
                    'agentIndex' => null,
                ],
                SignalConstants::SIGNAL_HANDSHAKE => [
                    'agentType' => 'chat',
                    'agentIndex' => null,
                ],
                SignalConstants::SIGNAL_CLOSE => [
                    'agentType' => 'chat',
                    'agentIndex' => null,
                ],
            ],
        ];
    }
}

