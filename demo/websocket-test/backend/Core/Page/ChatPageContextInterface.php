<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Page;

use Demo\WebSocketTest\Constants\ChatEventType;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\Exception\DatabaseException;

/**
 * ChatPageContextInterface - Interface for ChatAgent context methods
 *
 * Provides access to ChatAgent methods needed by page handlers.
 */
interface ChatPageContextInterface
{
    /**
     * Add event to history
     *
     * @param ChatEventType $type Event type
     * @param ?int $userId User ID (null for system events)
     * @param ?array $data Event-specific data (optional)
     * @param ?EntitiesChangesDTO $entities Entity updates for broadcast (optional)
     * @param ?string $excludeAcceptKey Accept key to exclude from receiving the event (optional)
     * @throws DatabaseException If database operation fails
     */
    public function addEvent(
        ChatEventType $type,
        ?int $userId = null,
        ?array $data = null,
        ?EntitiesChangesDTO $entities = null,
        ?string $excludeAcceptKey = null,
    ): void;

    /**
     * Get agent signal source
     *
     * @return SignalSourceInterface Agent signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface;
}
