<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Utils\DTO;

use Hilos\Utils\DTO\WebSocket\WebSocketUnsubscribeSignalDTO as FrameworkWebSocketUnsubscribeSignalDTO;

/**
 * WebSocketUnsubscribeSignalDTO - DTO for WebSocket unsubscribe signal
 *
 * Represents an unsubscribe signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketUnsubscribeSignalDTO for chat-specific functionality.
 */
class WebSocketUnsubscribeSignalDTO extends FrameworkWebSocketUnsubscribeSignalDTO implements ChatMessageDTOInterface
{
    // Inherits all functionality from framework DTO
}

