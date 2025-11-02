<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO\Agent;

use Hilos\Utils\DTO\BaseDTO;

/**
 * MessageFromUserDTO - DTO for messages from user to agent
 *
 * Represents a message sent from external user (WebSocket, HTTP, etc.) to agent.
 */
class MessageFromUserDTO extends BaseDTO implements AgentMessageDTOInterface
{
    // Field name constants
    public const string USER_ID = 'userId';
    public const string ACTION = 'action';
    public const string PAYLOAD = 'payload';

    public function __construct(
        public readonly string $userId,
        public readonly string $action,
        public readonly array $payload = [],
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
            self::USER_ID => $this->userId,
            self::ACTION => $this->action,
            self::PAYLOAD => $this->payload,
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
        return new self(
            userId: $data[self::USER_ID] ?? '',
            action: $data[self::ACTION] ?? '',
            payload: $data[self::PAYLOAD] ?? [],
        );
    }
}

