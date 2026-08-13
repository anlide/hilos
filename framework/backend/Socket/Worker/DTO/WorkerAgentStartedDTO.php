<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerAgentStartedDTO - DTO for agent started notification.
 *
 * Used when worker notifies daemon that agent has started.
 */
class WorkerAgentStartedDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = 'agent_started';

    /**
     * Creates agent started DTO.
     *
     * @param string $agentId Agent unique ID
     * @param string $agentType Agent type constant
     * @param ?string $agentIndex Agent index or null
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $agentType,
        public readonly ?string $agentIndex = null,
    ) {
    }

    /**
     * Get message type.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        $result = [
            self::TYPE => self::MESSAGE_TYPE,
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
            AgentConstants::FIELD_AGENT_TYPE => $this->agentType,
        ];

        if ($this->agentIndex !== null) {
            $result[AgentConstants::FIELD_AGENT_INDEX] = $this->agentIndex;
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The index is the one field this notification is allowed to arrive without:
     * an agent that carries no index leaves the key out of its own toArray().
     *
     * @param array<string, mixed> $data Source data (agentId, agentType, agentIndex)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no agent id or no agent type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            agentType: self::requireString($data, AgentConstants::FIELD_AGENT_TYPE),
            agentIndex: self::optionalString($data, AgentConstants::FIELD_AGENT_INDEX),
        );
    }
}
