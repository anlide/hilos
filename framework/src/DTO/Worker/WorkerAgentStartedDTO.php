<?php

declare(strict_types=1);

namespace Hilos\DTO\Worker;

use Hilos\DTO\BaseDTO;

/**
 * WorkerAgentStartedDTO - DTO for agent started notification
 *
 * Used when worker notifies daemon that agent has started.
 */
class WorkerAgentStartedDTO extends BaseDTO
{
    // Field name constants
    public const string TYPE = 'type';
    public const string AGENT_ID = 'agentId';
    public const string AGENT_TYPE = 'agentType';
    public const string AGENT_INDEX = 'agentIndex';

    // Message type
    public const string MESSAGE_TYPE = 'agent_started';

    public function __construct(
        public readonly string $agentId,
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
            self::TYPE => self::MESSAGE_TYPE,
            self::AGENT_ID => $this->agentId,
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
            agentId: $data[self::AGENT_ID] ?? '',
            agentType: $data[self::AGENT_TYPE] ?? '',
            agentIndex: $data[self::AGENT_INDEX] ?? null,
        );
    }
}
