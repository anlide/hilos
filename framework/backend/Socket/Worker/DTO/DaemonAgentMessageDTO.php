<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * DaemonAgentMessageDTO - DTO for agent message signal from daemon to worker
 *
 * Used when daemon sends agent_message signal to worker.
 * This is for messages from daemon to worker agent.
 */
class DaemonAgentMessageDTO extends WorkerDTO
{
    // Field name constants
    public const string AGENT_ID = 'agentId';
    public const string SIGNAL = 'signal';

    // Message type (daemon -> worker; distinct from WorkerAgentMessageDTO for worker -> daemon)
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DAEMON_AGENT_MESSAGE;

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
        public readonly SignalDTO $signal,
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
            self::SIGNAL => $this->signal->toArray(),
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
        $signalData = $data[self::SIGNAL] ?? [];
        $signal = $signalData instanceof SignalDTO
            ? $signalData
            : SignalDTO::fromArray($signalData);

        return new self(
            agentId: $data[self::AGENT_ID] ?? '',
            signal: $signal,
        );
    }
}
