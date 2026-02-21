<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\DTO;

use Hilos\BaseDTO;

/**
 * MessageFromAgentDTO - DTO for messages from another agent
 *
 * Represents a message sent from one agent to another agent.
 */
class MessageFromAgentDTO extends BaseDTO implements AgentMessageDTOInterface
{
    // Field name constants
    public const string FROM_AGENT_ID = 'fromAgentId';
    public const string ACTION = 'action';
    public const string PAYLOAD = 'payload';

    public function __construct(
        public readonly string $fromAgentId,
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
            self::FROM_AGENT_ID => $this->fromAgentId,
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
            fromAgentId: $data[self::FROM_AGENT_ID] ?? '',
            action: $data[self::ACTION] ?? '',
            payload: $data[self::PAYLOAD] ?? [],
        );
    }
}
