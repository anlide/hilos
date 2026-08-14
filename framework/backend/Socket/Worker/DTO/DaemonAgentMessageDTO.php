<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\HilosException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * DaemonAgentMessageDTO - DTO for agent message signal from daemon to worker.
 *
 * Used when daemon sends agent_message signal to worker.
 * This is for messages from daemon to worker agent.
 */
class DaemonAgentMessageDTO extends WorkerDTO
{
    public const string SIGNAL = 'signal';

    // Message type (daemon -> worker; distinct from WorkerAgentMessageDTO for worker -> daemon)
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DAEMON_AGENT_MESSAGE;

    /**
     * Creates daemon agent message DTO.
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
        return [
            self::TYPE => $this->getType(),
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
            self::SIGNAL => $this->signal->toArray(),
        ];
    }

    /**
     * Creates DTO from array.
     *
     * The signal is required and read as an array of its own, so a message that
     * arrives without one is refused under that name instead of reaching
     * {@see SignalDTO::fromArray()} as an empty payload and being refused there
     * under the name of a field nested inside it. A signal handed over in
     * process, without a serialization round trip, arrives as the object and is
     * taken as it is.
     *
     * @param array<string, mixed> $data Source data (agentId, signal)
     * @return static DTO instance
     * @throws InvalidArgumentException When the signal names an empty signal
     * @throws InvalidFormatException When the payload carries no agent id or no signal
     * @throws HilosException When the inner signal payload refuses to be restored
     */
    public static function fromArray(array $data): static
    {
        $signalData = $data[self::SIGNAL] ?? null;
        $signal = $signalData instanceof SignalDTO
            ? $signalData
            : SignalDTO::fromArray(self::requireArray($data, self::SIGNAL));

        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            signal: $signal,
        );
    }
}
