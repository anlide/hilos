<?php

declare(strict_types=1);

namespace Hilos\DTO\Worker;

use Hilos\DTO\BaseDTO;

/**
 * WorkerAgentMessageDTO - DTO for agent message from worker
 *
 * Used when worker sends agent message to daemon.
 */
class WorkerAgentMessageDTO extends BaseDTO
{
    // Field name constants
    public const string TYPE = 'type';
    public const string AGENT_ID = 'agentId';
    public const string DATA = 'data';

    // Message type
    public const string MESSAGE_TYPE = 'agent_message';

    public function __construct(
        public readonly string $agentId,
        public readonly array $data = [],
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
        ];

        if (!empty($this->data)) {
            $result[self::DATA] = $this->data;
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
            data: $data[self::DATA] ?? [],
        );
    }
}

