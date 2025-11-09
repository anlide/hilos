<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO\Worker;

use Hilos\Utils\Constants\WorkerConstants;
use Hilos\Utils\DTO\SignalDTO;

/**
 * DaemonAgentMessageDTO - DTO for agent message signal from daemon to worker
 *
 * Used when daemon sends agent_message signal to worker.
 * This is for messages from daemon to worker agent.
 */
class DaemonAgentMessageDTO extends WorkerDTO
{
    // Field name constants
    public const string AGENT_TYPE = 'agentType';
    public const string AGENT_INDEX = 'agentIndex';
    public const string SIGNAL = 'signal';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_AGENT_MESSAGE;

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
        public readonly ?string $agentIndex,
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
        $result = [
            self::TYPE => $this->getType(),
            self::AGENT_TYPE => $this->agentType,
            self::SIGNAL => $this->signal->toArray(),
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
        $signalData = $data[self::SIGNAL] ?? [];
        $signal = $signalData instanceof SignalDTO
            ? $signalData
            : SignalDTO::fromArray($signalData);

        return new self(
            agentType: $data[self::AGENT_TYPE] ?? '',
            agentIndex: $data[self::AGENT_INDEX] ?? null,
            signal: $signal,
        );
    }
}


