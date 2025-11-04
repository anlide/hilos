<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Utils\DTO;

use Hilos\Utils\DTO\WebSocket\WebSocketFrameSignalDTO as FrameworkWebSocketFrameSignalDTO;

/**
 * WebSocketFrameSignalDTO - DTO for WebSocket text frame signal
 *
 * Represents a WebSocket text frame signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketFrameSignalDTO for chat-specific functionality.
 */
class WebSocketFrameSignalDTO extends FrameworkWebSocketFrameSignalDTO implements ChatMessageDTOInterface
{
    // Inherits all functionality from framework DTO
}
