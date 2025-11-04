<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Utils\DTO;

use Hilos\Utils\DTO\WebSocket\WebSocketCloseSignalDTO as FrameworkWebSocketCloseSignalDTO;

/**
 * WebSocketCloseSignalDTO - DTO for WebSocket close signal
 *
 * Represents a WebSocket close signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketCloseSignalDTO for chat-specific functionality.
 */
class WebSocketCloseSignalDTO extends FrameworkWebSocketCloseSignalDTO implements ChatMessageDTOInterface
{
    // Inherits all functionality from framework DTO
}
