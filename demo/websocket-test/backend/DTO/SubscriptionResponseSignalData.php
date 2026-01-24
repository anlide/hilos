<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;
use RuntimeException;

/**
 * SubscriptionResponseSignalData - Signal data for subscription response
 *
 * Contains chat history events and user info for new subscribers.
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 */
class SubscriptionResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        /** @var array<array{id: int, type: string, timestamp: int, data: array<string, mixed>}> Chat event history */
        public readonly array $events,
        /** @var int User ID */
        public readonly int $userId,
        /** @var string Username */
        public readonly string $username,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        return [
            'events' => $this->events,
            'userId' => $this->userId,
            'username' => $this->username,
        ];
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        // This is not used for deserialization from array
        // Response is created directly in ChatAgent
        throw new RuntimeException('SubscriptionResponseSignalData::fromArray() is not implemented');
    }
}
