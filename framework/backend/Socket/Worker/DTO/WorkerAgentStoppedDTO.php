<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerAgentStoppedDTO - DTO for agent stopped notification.
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

    /**
     * Creates agent stopped notification DTO.
     *
     * @param string $agentId Agent ID that stopped
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
     * @return array<string, string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
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
