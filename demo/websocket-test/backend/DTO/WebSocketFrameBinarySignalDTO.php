<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\DTO\WebSocket\WebSocketFrameBinarySignalDTO as FrameworkWebSocketFrameBinarySignalDTO;

/**
 * WebSocketFrameBinarySignalDTO - DTO for WebSocket binary frame signal
 *
 * Represents a WebSocket binary frame signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketFrameBinarySignalDTO for chat-specific functionality.
 */
class WebSocketFrameBinarySignalDTO extends FrameworkWebSocketFrameBinarySignalDTO implements ChatMessageDTOInterface
{
    // Inherits all functionality from framework DTO
}
