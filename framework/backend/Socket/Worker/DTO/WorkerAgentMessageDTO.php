<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerAgentMessageDTO - DTO for agent message from worker to daemon
 *
 * Used when worker sends agent message to daemon.
 */
class WorkerAgentMessageDTO extends WorkerDTO
{
    // Field name constants
    public const string TYPE = 'type';
    public const string AGENT_ID = 'agentId';
    public const string SIGNAL = 'signal';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_AGENT_MESSAGE;

    public function __construct(
        public readonly string $agentId,
        public readonly SignalDTO $signal,
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
        $result = [
            self::TYPE => self::MESSAGE_TYPE,
            self::AGENT_ID => $this->agentId,
        ];

        if (!empty($this->signal)) {
            $result[self::SIGNAL] = $this->signal->toArray();
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
            signal: SignalDTO::fromArray($data[self::SIGNAL]),
        );
    }
}
