<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Utils\DTO;

use Hilos\Utils\DTO\WebSocket\WebSocketUpdateSubscriptionSignalDTO as FrameworkWebSocketUpdateSubscriptionSignalDTO;

/**
 * WebSocketUpdateSubscriptionSignalDTO - DTO for WebSocket update subscription signal
 *
 * Represents an update subscription signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketUpdateSubscriptionSignalDTO for chat-specific functionality.
 */
class WebSocketUpdateSubscriptionSignalDTO extends FrameworkWebSocketUpdateSubscriptionSignalDTO implements ChatMessageDTOInterface
{
    // Inherits all functionality from framework DTO
}

