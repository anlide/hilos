<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Demo\WebSocketTest\Domain\Event\ChatEventInterface;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use RuntimeException;

/**
 * ChatEventSignalData - Signal data for chat events
 *
 * Wraps ChatEventInterface to be used as SignalDataInterface.
 */
class ChatEventSignalData extends SignalData implements SignalDataInterface
{
    public function __construct(
        public readonly ChatEventInterface $event,
    ) {
        // Don't call parent::__construct() - we override toArray() to use event data
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        return $this->event->toArray();
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
        // Events are created directly in ChatAgent
        throw new RuntimeException('ChatEventSignalData::fromArray() is not implemented');
    }
}
