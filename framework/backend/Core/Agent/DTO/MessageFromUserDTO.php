<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\DTO;

use Hilos\BaseDTO;

/**
 * MessageFromUserDTO - DTO for messages from user to agent.
 *
 * Represents a message sent from external user (WebSocket, HTTP, etc.) to agent.
 */
class MessageFromUserDTO extends BaseDTO implements AgentMessageDTOInterface
{
    // Field name constants
    public const string USER_ID = 'userId';
    public const string ACTION = 'action';
    public const string PAYLOAD = 'payload';

    /**
     * Creates message from user DTO.
     *
     * @param string $userId User identifier
     * @param string $action Action name
     * @param array<string, mixed> $payload Action payload data
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $action,
        public readonly array $payload = [],
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with userId, action, payload keys
     */
    public function toArray(): array
    {
        return [
            self::USER_ID => $this->userId,
            self::ACTION => $this->action,
            self::PAYLOAD => $this->payload,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data with userId, action, payload keys
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: $data[self::USER_ID] ?? '',
            action: $data[self::ACTION] ?? '',
            payload: $data[self::PAYLOAD] ?? [],
        );
    }
}
