<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Demo\WebSocketTest\Domain\Event\ChatEventInterface;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;

/**
 * SubscriptionResponseSignalData - Signal data for subscription response
 *
 * Contains chat history events, server start time, and target client ID for new subscribers.
 */
class SubscriptionResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        /** @var array<ChatEventInterface> Chat event history */
        public readonly array $events,
        /** @var float Server start time (microtime) */
        public readonly float $startTime,
        /** @var string Target client ID */
        public readonly string $targetClientId,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        $eventsArray = [];
        foreach ($this->events as $event) {
            $eventsArray[] = $event->toArray();
        }

        return [
            'events' => $eventsArray,
            'startTime' => $this->startTime,
            'targetClientId' => $this->targetClientId,
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
        throw new \RuntimeException('SubscriptionResponseSignalData::fromArray() is not implemented');
    }
}
