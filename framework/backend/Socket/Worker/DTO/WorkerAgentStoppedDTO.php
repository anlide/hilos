<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerAgentStoppedDTO - DTO for agent stopped notification
 *
 * Used when worker notifies daemon that agent has stopped.
 */
class WorkerAgentStoppedDTO extends WorkerDTO
{
    // Field name constants
    public const string TYPE = 'type';
    public const string AGENT_ID = 'agentId';

    // Message type
    public const string MESSAGE_TYPE = 'agent_stopped';

    public function __construct(
        public readonly string $agentId,
    ) {
    }

    /**
     * Get message type
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
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
