<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Constants\AgentConstants;

/**
 * Claim frame the leader sends the one node whose agent claimed rows another node already holds.
 *
 * The only answer a {@see PeerRtClaimsDTO} ever gets: a claim the leader accepted is answered
 * with silence, so acknowledging every report would be traffic with no reader. Addressed to
 * the losing node alone — the holder is working correctly and is never told anything.
 *
 * Who lost is decided by arrival, not by comparing node ids: the first report the leader folded
 * in owns the rows, and this frame goes to whoever claimed them afterwards.
 *
 * The frame names both sides in full because the node that receives it has to be able to say
 * why it stopped an agent without the reader going to the leader's log for the other half.
 */
final class PeerRtClaimRefusedDTO extends PeerDTO
{
    /** @var string Wire message type for the RT-claim-refused frame */
    public const string MESSAGE_TYPE = 'peer_rt_claim_refused';

    /** @var string Payload key: RT collection the refused claim is over */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /** @var string Payload key: the rows the refusal is over, or none for the whole collection */
    public const string FIELD_STATE_IDS = 'stateIds';

    /** @var string Payload key: id of the node that holds the rows already */
    public const string FIELD_HOLDER_NODE_ID = 'holderNodeId';

    /** @var string Payload key: agent of that node that holds them */
    public const string FIELD_HOLDER_AGENT_ID = 'holderAgentId';

    /**
     * @param string $collectionKey RT collection the refused claim is over
     * @param list<string> $stateIds Rows the refusal is over; empty for the whole collection
     * @param string $agentType Type of the agent whose claim is refused
     * @param ?string $agentIndex Index of that agent, or null for a singleton agent
     * @param string $agentId Id of that agent, as its own node keys it
     * @param string $holderNodeId Node that holds the rows already
     * @param string $holderAgentId Agent of that node that holds them
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly array $stateIds,
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly string $agentId,
        public readonly string $holderNodeId,
        public readonly string $holderAgentId,
    ) {
    }

    /**
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Serializes the refusal to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_COLLECTION_KEY => $this->collectionKey,
            self::FIELD_STATE_IDS => $this->stateIds,
            AgentConstants::FIELD_AGENT_TYPE => $this->agentType,
            AgentConstants::FIELD_AGENT_INDEX => $this->agentIndex,
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
            self::FIELD_HOLDER_NODE_ID => $this->holderNodeId,
            self::FIELD_HOLDER_AGENT_ID => $this->holderAgentId,
        ];
    }

    /**
     * Restores a refusal from its wire array.
     *
     * Every name in it is load-bearing — the frame stops an agent and says which one, over what,
     * and in whose favour — so a frame missing any of them is refused whole rather than acted on
     * half-read. Only the row list may be absent, and its absence is the meaning it carries
     * everywhere else in this protocol: the whole collection.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored refusal
     * @throws PeerTransportException When the frame names no collection, no agent, or no holder
     */
    public static function fromArray(array $data): static
    {
        $collectionKey = self::readName($data, self::FIELD_COLLECTION_KEY, 'collection');
        $stateIds = [];
        $rawStateIds = $data[self::FIELD_STATE_IDS] ?? [];
        if (is_array($rawStateIds)) {
            foreach ($rawStateIds as $stateId) {
                if (is_string($stateId) && $stateId !== '') {
                    $stateIds[] = $stateId;
                }
            }
        }

        return new static(
            collectionKey: $collectionKey,
            stateIds: $stateIds,
            agentType: self::readName($data, AgentConstants::FIELD_AGENT_TYPE, 'refused agent type'),
            agentIndex: PeerPlacedAgentEntry::readAgentIndex($data[AgentConstants::FIELD_AGENT_INDEX] ?? null),
            agentId: self::readName($data, AgentConstants::FIELD_AGENT_ID, 'refused agent id'),
            holderNodeId: self::readName($data, self::FIELD_HOLDER_NODE_ID, 'holder node'),
            holderAgentId: self::readName($data, self::FIELD_HOLDER_AGENT_ID, 'holder agent'),
        );
    }

    /**
     * Reads one required name out of the frame.
     *
     * @param array<string, mixed> $data Frame payload
     * @param string $field Payload key holding the name
     * @param string $what How the missing name is described in the failure message
     * @return string Name as read from the wire
     * @throws PeerTransportException When the field is absent, not a string, or blank
     */
    private static function readName(array $data, string $field, string $what): string
    {
        $value = $data[$field] ?? null;
        $name = is_string($value) ? trim($value) : null;
        if ($name === null || $name === '') {
            throw new PeerTransportException("Peer RT claim refusal is missing the {$what}");
        }

        return $name;
    }
}
