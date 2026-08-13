<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * ProtectedModeReadyDTO - relays the leader's ready to the initiator agent's worker.
 *
 * Sent daemon -> worker on the initiator node once {@see ClusterProtectedMode}
 * has every follower quiesced: the freeze is active, so the initiator agent may run its destructive
 * operation. It names the addressed agent and carries nothing else — the arrival is the whole
 * message, mirroring {@see AgentStopDTO}.
 */
class ProtectedModeReadyDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_READY;

    /**
     * Creates the ready relay DTO.
     *
     * @param string $agentId Initiator agent unique id to notify
     */
    public function __construct(
        public readonly string $agentId,
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
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (agentId)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no agent id
     */
    public static function fromArray(array $data): static
    {
        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
        );
    }
}
