<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerRtSourceReleasedDTO - a stopped agent's RT collections, given back to the daemon.
 *
 * The mirror of {@see WorkerRtSourceRegisteredDTO}: the worker unregisters the agent from its
 * truth-source registry when the stop hook returns, and the master drops what that agent
 * owned. Only the agent is named — what it held is the master's record, and after this it
 * holds nothing.
 */
class WorkerRtSourceReleasedDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SOURCE_RELEASED;

    /**
     * Creates RT source released DTO.
     *
     * @param string $agentId Agent that no longer owns RT collections on this node
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
            self::TYPE => self::MESSAGE_TYPE,
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
        return new static(agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID));
    }
}
