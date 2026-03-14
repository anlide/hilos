<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\DTO;

use Hilos\BaseDTO;

/**
 * MessageFromAgentDTO - DTO for messages from another agent.
 *
 * Represents a message sent from one agent to another agent.
 */
class MessageFromAgentDTO extends BaseDTO implements AgentMessageDTOInterface
{
    // Field name constants
    public const string FROM_AGENT_ID = 'fromAgentId';
    public const string ACTION = 'action';
    public const string PAYLOAD = 'payload';

    /**
     * Creates message from agent DTO.
     *
     * @param string $fromAgentId Source agent ID
     * @param string $action Action name
     * @param array<string, mixed> $payload Action payload data
     */
    public function __construct(
        public readonly string $fromAgentId,
        public readonly string $action,
        public readonly array $payload = [],
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with fromAgentId, action, payload keys
     */
    public function toArray(): array
    {
        return [
            self::FROM_AGENT_ID => $this->fromAgentId,
            self::ACTION => $this->action,
            self::PAYLOAD => $this->payload,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data with fromAgentId, action, payload keys
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            fromAgentId: $data[self::FROM_AGENT_ID] ?? '',
            action: $data[self::ACTION] ?? '',
            payload: $data[self::PAYLOAD] ?? [],
        );
    }
}
