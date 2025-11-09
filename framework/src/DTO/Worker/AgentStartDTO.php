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
    public const string AGENT_TYPE = 'agentType';
    public const string AGENT_INDEX = 'agentIndex';

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
        public readonly string $agentType,
        public readonly ?string $agentIndex = null,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        $result = [
            self::TYPE => $this->getType(),
            self::AGENT_TYPE => $this->agentType,
        ];

        if ($this->agentIndex !== null) {
            $result[self::AGENT_INDEX] = $this->agentIndex;
        }

        return $result;
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
            agentType: $data[self::AGENT_TYPE] ?? '',
            agentIndex: $data[self::AGENT_INDEX] ?? null,
        );
    }
}
