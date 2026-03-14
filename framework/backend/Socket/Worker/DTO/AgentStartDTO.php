<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * AgentStartDTO - DTO for agent start signal.
 *
 * Used when daemon sends agent_start signal to worker.
 */
class AgentStartDTO extends WorkerDTO
{
    // Field name constants
    public const string AGENT_ID = 'agentId';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_AGENT_START;

    /**
     * Creates agent start DTO.
     *
     * @param string $agentId Agent unique ID to start
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
            self::AGENT_ID => $this->agentId,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (agentId)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            agentId: $data[self::AGENT_ID] ?? '',
        );
    }
}
