<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Page;

use Demo\WebSocketTest\Constants\ChatEventType;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\DTO\EntitiesChangesDTO;

/**
 * ChatPageAgentInterface - Interface for ChatAgent methods accessible from Page
 *
 * Extends PageAgentInterface with chat-specific methods.
 */
interface ChatPageAgentInterface extends PageAgentInterface
{
    /**
     * Add event to history and broadcast
     *
     * @param ChatEventType $type Event type
     * @param ?int $userId User ID (null for system events)
     * @param ?array $data Event-specific data (optional)
     * @param ?EntitiesChangesDTO $entities Entity updates for broadcast (optional)
     * @param ?string $excludeAcceptKey Accept key to exclude from receiving the event (optional)
     */
    public function addEvent(
        ChatEventType $type,
        ?int $userId = null,
        ?array $data = null,
        ?EntitiesChangesDTO $entities = null,
        ?string $excludeAcceptKey = null,
    ): void;
}
