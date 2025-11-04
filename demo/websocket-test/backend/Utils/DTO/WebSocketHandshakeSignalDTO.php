<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Utils\DTO;

use Hilos\Utils\DTO\WebSocket\WebSocketHandshakeSignalDTO as FrameworkWebSocketHandshakeSignalDTO;

/**
 * WebSocketHandshakeSignalDTO - DTO for WebSocket handshake signal
 *
 * Represents a WebSocket handshake signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketHandshakeSignalDTO for chat-specific functionality.
 */
class WebSocketHandshakeSignalDTO extends FrameworkWebSocketHandshakeSignalDTO implements ChatMessageDTOInterface
{
    // Inherits all functionality from framework DTO
}
