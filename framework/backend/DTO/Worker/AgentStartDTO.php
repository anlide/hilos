<?php

declare(strict_types=1);

namespace Hilos\DTO\Worker;

use Hilos\Constants\WorkerConstants;

/**
 * AgentStartDTO - DTO for agent start signal
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
     * Get message type
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    public function __construct(
        public readonly string $agentId,
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
            self::TYPE => $this->getType(),
            self::AGENT_ID => $this->agentId,
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
            agentId: $data[self::AGENT_ID] ?? '',
        );
    }
}
