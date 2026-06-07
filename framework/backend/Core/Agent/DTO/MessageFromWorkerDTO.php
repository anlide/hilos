<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\DTO;

use Hilos\BaseDTO;

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
     * @param array<string, mixed> $data Source data with action and optional payload keys
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            action: $data[self::ACTION] ?? '',
            payload: $data[self::PAYLOAD] ?? [],
        );
    }
}
