<?php

declare(strict_types=1);

namespace Demo\Chat\DTO;

use Demo\Chat\Database\Idea\Event as IdeaEvent;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\EntitiesChangesDTO;
use RuntimeException;

/**
 * ChatEventSignalDTO - Signal data for chat events
 *
 * Wraps IdeaEvent to be used as SignalDataInterface.
 */
class ChatEventSignalDTO extends SignalData implements SignalDataInterface
{
    public function __construct(
        public readonly IdeaEvent $event,
        public readonly ?EntitiesChangesDTO $entities = null,
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
        if ($this->event->id === null) {
            throw new RuntimeException("Cannot convert event to array: id is null");
        }

        $timestamp = strtotime($this->event->timestamp);
        if ($timestamp === false) {
            $timestamp = 0;
        }

        // Parse data from JSON string and merge userId if not already present
        $data = $this->event->data === null ? [] : json_decode($this->event->data, true) ?? [];
        if ($this->event->userId !== null && !isset($data['userId'])) {
            $data['userId'] = $this->event->userId;
        }

        $payload = [
            'id' => $this->event->id,
            'type' => $this->event->type,
            'timestamp' => $timestamp,
            'data' => $data,
        ];

        if ($this->entities !== null) {
            $entities = $this->entities->toArray();
            if ($entities !== []) {
                $payload['entities'] = $entities;
            }
        }

        return $payload;
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
        throw new RuntimeException('ChatEventSignalDTO::fromArray() is not implemented');
    }
}
