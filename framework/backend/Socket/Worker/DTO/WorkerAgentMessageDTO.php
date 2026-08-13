<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerAgentMessageDTO - DTO for agent message from worker to daemon.
 *
 * Used when worker sends agent message to daemon.
 */
class WorkerAgentMessageDTO extends WorkerDTO
{
    public const string SIGNAL = 'signal';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_AGENT_MESSAGE;

    /**
     * Creates agent message DTO.
     *
     * @param string $agentId Agent ID
     * @param SignalDTO $signal Signal payload
     */
    public function __construct(
        public readonly string $agentId,
        public readonly SignalDTO $signal,
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
        $result = [
            self::TYPE => self::MESSAGE_TYPE,
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
        ];

        if (!empty($this->signal)) {
            $result[self::SIGNAL] = $this->signal->toArray();
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (agentId, signal)
     * @return static DTO instance
     * @throws InvalidArgumentException When the signal names an empty signal
     * @throws InvalidFormatException When the payload carries no agent id or no signal
     */
    public static function fromArray(array $data): static
    {
        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            signal: SignalDTO::fromArray(self::requireArray($data, self::SIGNAL)),
        );
    }
}
