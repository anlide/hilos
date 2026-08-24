<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * AgentStartDTO - DTO for agent start signal.
 *
 * Used when daemon sends agent_start signal to worker.
 *
 * The frame also carries the node's live sockets (HIL-664), because starting an agent is the
 * moment its runtime connection rows can be trusted again: the master names who is still on
 * the wire, and the worker strikes out the rows of everyone who is not.
 */
class AgentStartDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_AGENT_START;

    /**
     * Creates agent start DTO.
     *
     * @param string $agentId Agent unique ID to start
     * @param list<string> $liveAcceptKeys Accept keys of the node's live sockets, empty when it holds none
     */
    public function __construct(
        public readonly string $agentId,
        public readonly array $liveAcceptKeys = [],
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
            AgentConstants::FIELD_LIVE_ACCEPT_KEYS => $this->liveAcceptKeys,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * A frame with no key list at all reads as a node holding no socket, rather than as a
     * malformed frame: the field is younger than the frame, and a worker meeting the older
     * shape must start its agent, not refuse it.
     *
     * @param array<string, mixed> $data Source data (agentId, liveAcceptKeys)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no agent id, or a non-array key list
     */
    public static function fromArray(array $data): static
    {
        $liveAcceptKeys = [];
        foreach (self::optionalArray($data, AgentConstants::FIELD_LIVE_ACCEPT_KEYS) ?? [] as $acceptKey) {
            if (is_string($acceptKey) && $acceptKey !== '') {
                $liveAcceptKeys[] = $acceptKey;
            }
        }

        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            liveAcceptKeys: $liveAcceptKeys,
        );
    }
}
