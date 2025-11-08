<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Utils\DTO;

use Hilos\Utils\DTO\WebSocket\WebSocketActionSignalDTO as FrameworkWebSocketActionSignalDTO;

/**
 * WebSocketActionSignalDTO - DTO for WebSocket action signal
 *
 * Represents an action signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketActionSignalDTO for chat-specific functionality.
 */
class WebSocketActionSignalDTO extends FrameworkWebSocketActionSignalDTO implements ChatMessageDTOInterface
{
    // Inherits all functionality from framework DTO
}

