<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * ProtectedModeRefusedDTO - relays the refusal to the initiator agent's worker.
 *
 * Sent daemon -> worker on the initiator node when a protected-mode enable or re-entry request
 * is refused, allowing the agent to end its restore operation immediately with a reason.
 */
class ProtectedModeRefusedDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_REFUSED;

    /**
     * Creates the refusal relay DTO.
     *
     * @param string $agentId Initiator agent unique id to notify
     * @param string $reason Human-readable operator-facing refusal message
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $reason,
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
        return [
            self::TYPE => $this->getType(),
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
            AgentConstants::FIELD_REASON => $this->reason,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (agentId, reason)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no agent id or reason
     */
    public static function fromArray(array $data): static
    {
        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            reason: self::requireString($data, AgentConstants::FIELD_REASON),
        );
    }
}
