<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/** One node asks another master to restart an agent, retaining the command's return address. */
final class PeerAgentRestartDTO extends PeerDTO
{
    /** @var string Peer wire message type */
    public const string MESSAGE_TYPE = 'peer_agent_restart';

    /** @var string Command correlation id field */
    public const string FIELD_CORRELATION_ID = 'correlationId';

    /** @var string Asking node field */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Agent type field */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Agent index field */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /** @var string Requested node field */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Leader's in-place sanction field */
    public const string FIELD_IN_PLACE = 'inPlace';

    /**
     * @param string $correlationId Command correlation id
     * @param string $originNodeId Node holding the command connection
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton
     * @param ?string $nodeId Requested node, or null for leader-selected placement
     * @param bool $inPlace Whether the leader sanctioned a POLICY restart on this host
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $originNodeId,
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly ?string $nodeId,
        public readonly bool $inPlace,
    ) {
    }

    /** @return string Peer wire message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_CORRELATION_ID => $this->correlationId,
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
            self::FIELD_AGENT_TYPE => $this->agentType,
            self::FIELD_AGENT_INDEX => $this->agentIndex,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_IN_PLACE => $this->inPlace,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored restart frame
     * @throws PeerTransportException When a required field is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        foreach ([self::FIELD_CORRELATION_ID, self::FIELD_ORIGIN_NODE_ID, self::FIELD_AGENT_TYPE] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new PeerTransportException("Peer agent restart is missing {$field}");
            }
        }
        if (!array_key_exists(self::FIELD_AGENT_INDEX, $data)
            || ($data[self::FIELD_AGENT_INDEX] !== null
                && (!is_string($data[self::FIELD_AGENT_INDEX]) || $data[self::FIELD_AGENT_INDEX] === ''))) {
            throw new PeerTransportException('Peer agent restart carries an invalid agent index');
        }
        if (!array_key_exists(self::FIELD_NODE_ID, $data)
            || ($data[self::FIELD_NODE_ID] !== null && (!is_string($data[self::FIELD_NODE_ID]) || $data[self::FIELD_NODE_ID] === ''))) {
            throw new PeerTransportException('Peer agent restart carries an invalid node id');
        }
        if (!isset($data[self::FIELD_IN_PLACE]) || !is_bool($data[self::FIELD_IN_PLACE])) {
            throw new PeerTransportException('Peer agent restart is missing inPlace');
        }

        return new static(
            $data[self::FIELD_CORRELATION_ID],
            $data[self::FIELD_ORIGIN_NODE_ID],
            $data[self::FIELD_AGENT_TYPE],
            $data[self::FIELD_AGENT_INDEX],
            $data[self::FIELD_NODE_ID],
            $data[self::FIELD_IN_PLACE],
        );
    }
}
