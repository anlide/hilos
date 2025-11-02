<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Router;

use Hilos\Core\Router\SignalRouter;

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
        // Routes WebSocket signals (frame, handshake, close) to chat agent
        $this->config = [
            'websocket' => [
                'frame' => [
                    'agentType' => 'chat',
                    'agentIndex' => null,
                ],
                'handshake' => [
                    'agentType' => 'chat',
                    'agentIndex' => null,
                ],
                'close' => [
                    'agentType' => 'chat',
                    'agentIndex' => null,
                ],
            ],
        ];
    }
}

