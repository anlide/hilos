<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO;

    /**
     * AgentMessageDTO - Data Transfer Object for agent signals
     *
     * Used for communication between daemon and worker processes.
     * Supports different signal types: agent_start, agent_stop, agent_message.
     */
class AgentMessageDTO extends BaseDTO
{
    // Field name constants
    public const string TYPE = 'type';
    public const string AGENT_ID = 'agentId';
    public const string AGENT_TYPE = 'agentType';
    public const string AGENT_INDEX = 'agentIndex';
    public const string ACTION = 'action';
    public const string DATA = 'data';

    // Signal types
    public const string TYPE_AGENT_START = 'agent_start';
    public const string TYPE_AGENT_STOP = 'agent_stop';
    public const string TYPE_AGENT_SIGNAL = 'agent_message';

    // Actions (for TYPE_AGENT_SIGNAL)
    public const string ACTION_SIGNAL = 'signal';
    public const string ACTION_REQUEST = 'request';
    public const string ACTION_RESPONSE = 'response';
    public const string ACTION_BROADCAST = 'broadcast';

    public function __construct(
        public readonly string $type,
        public readonly string $agentId,
        public readonly string $agentType,
        public readonly ?string $agentIndex = null,
        public readonly string $action = '',
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
            self::TYPE => $this->type,
            self::AGENT_ID => $this->agentId,
            self::AGENT_TYPE => $this->agentType,
        ];

        if ($this->agentIndex !== null) {
            $result[self::AGENT_INDEX] = $this->agentIndex;
        }

        if ($this->action !== '') {
            $result[self::ACTION] = $this->action;
        }

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
            type: $data[self::TYPE] ?? '',
            agentId: $data[self::AGENT_ID] ?? '',
            agentType: $data[self::AGENT_TYPE] ?? '',
            agentIndex: $data[self::AGENT_INDEX] ?? null,
            action: $data[self::ACTION] ?? '',
            data: $data[self::DATA] ?? [],
        );
    }
}

