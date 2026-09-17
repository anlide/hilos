<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerAgentStartFailedDTO - DTO for an agent start that did not finish (HIL-629).
 *
 * Sent by a worker in place of {@see WorkerAgentStartedDTO} when the start refused before the
 * worker came to hold the agent: the state it reads did not arrive, the factory failed, or a
 * claim was refused. The master holds frames for an agent until one of the two reports arrives,
 * and wrote its own record of the agent before the worker did anything - without this report that
 * record would say "linked and coming up" for good.
 */
class WorkerAgentStartFailedDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_AGENT_START_FAILED;

    /**
     * Creates agent start failed DTO.
     *
     * @param string $agentId Agent unique ID
     * @param string $agentType Agent type constant
     * @param ?string $agentIndex Agent index or null
     * @param string $reason Why the start did not finish, as the failure said it
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $agentType,
        public readonly ?string $agentIndex = null,
        public readonly string $reason = '',
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

        $result[AgentConstants::FIELD_REASON] = $this->reason;

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The index is left out by an agent that carries none, exactly as the started report leaves
     * it out; the reason always travels, because a failure always has a message, even an empty one.
     *
     * @param array<string, mixed> $data Source data (agentId, agentType, agentIndex, reason)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no agent id, no agent type or no reason
     */
    public static function fromArray(array $data): static
    {
        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            agentType: self::requireString($data, AgentConstants::FIELD_AGENT_TYPE),
            agentIndex: self::optionalString($data, AgentConstants::FIELD_AGENT_INDEX),
            reason: self::requireString($data, AgentConstants::FIELD_REASON),
        );
    }
}
