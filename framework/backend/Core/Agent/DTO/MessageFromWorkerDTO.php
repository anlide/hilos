<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * MessageFromWorkerDTO - DTO for messages from worker to agent.
 *
 * Represents a message sent from worker process management to agent.
 */
class MessageFromWorkerDTO extends BaseDTO implements AgentMessageDTOInterface
{
    // Field name constants
    public const string ACTION = 'action';
    public const string PAYLOAD = 'payload';

    /**
     * Creates message from worker DTO.
     *
     * @param string $action Action name (e.g. worker_ready)
     * @param array<string, mixed> $payload Action payload data
     */
    public function __construct(
        public readonly string $action,
        public readonly array $payload = [],
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with action and optional payload keys
     */
    public function toArray(): array
    {
        $result = [
            self::ACTION => $this->action,
        ];

        if (!empty($this->payload)) {
            $result[self::PAYLOAD] = $this->payload;
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The payload is legitimately absent: {@see self::toArray()} leaves the key
     * out when there is nothing to carry, so a message without it is a whole
     * message and not a broken one. A key that is present but holds something
     * other than an array is broken, and is refused rather than emptied.
     *
     * @param array<string, mixed> $data Source data with action and optional payload keys
     * @return static DTO instance
     * @throws InvalidFormatException When the action is missing, or the payload is present and not an array
     */
    public static function fromArray(array $data): static
    {
        return new static(
            action: self::requireString($data, self::ACTION),
            payload: self::optionalArray($data, self::PAYLOAD) ?? [],
        );
    }
}
