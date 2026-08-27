<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Constants\AgentConstants;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\TruthSource\RtNodeSourceMap;

/**
 * Wire form of what one agent of a node owns, inside a {@see PeerRtClaimsDTO}.
 *
 * The right itself, told to the leader rather than inferred from traffic: a claim exists from
 * the moment an agent registers, which is before it has written a single row, and a snapshot
 * built out of rows says nothing at all in that window.
 *
 * The three ownership keys are spelled exactly as the worker's own report spells them
 * ({@see WorkerRtSourceRegisteredDTO}), because they carry exactly the same thing one hop
 * further: the collections the agent took, those of them it took only part of the operations
 * on, and those it took by naming rows. One thing named two ways on two wires is how the two
 * axes of the right end up judged differently at each end.
 *
 * The identity is carried in all three forms the leader needs it in — the id it keys by, and
 * the type and index a placement frame is addressed with — rather than parsed back out of the
 * id at the far end. Mirrors {@see PeerPlacedAgentEntry}'s role for placement.
 */
final class PeerRtClaimEntry extends BaseDTO
{
    /**
     * @param string $agentId Agent that holds the claim, as the node's map keys it
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param list<string> $collectionKeys RT collections the agent owns on its node
     * @param list<string> $partialCollectionKeys Those of them it owns with only part of the operations
     * @param array<string, list<string>> $keysByCollection Those of them it claimed by key, and the keys
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly array $collectionKeys,
        public readonly array $partialCollectionKeys = [],
        public readonly array $keysByCollection = [],
    ) {
    }

    /**
     * Whether this agent holds the WHOLE right over a collection, or over one row of it.
     *
     * The same question {@see RtNodeSourceMap::ownsFully()} answers about a node, asked of one
     * claim: every operation, and every row, since only two whole rights over the same rows are
     * the split the leader judges. A claim naming keys answers no about the collection around
     * them however complete its right over each of them is.
     *
     * @param string $collectionKey RT collection to ask about
     * @param ?string $stateId Row to narrow the question to, or null to ask about the collection
     * @return bool True when this agent owns it with every operation
     */
    public function ownsFully(string $collectionKey, ?string $stateId = null): bool
    {
        if (!in_array($collectionKey, $this->collectionKeys, true)) {
            return false;
        }
        if (in_array($collectionKey, $this->partialCollectionKeys, true)) {
            return false;
        }

        $claimedKeys = $this->keysByCollection[$collectionKey] ?? null;

        return $claimedKeys === null || ($stateId !== null && in_array($stateId, $claimedKeys, true));
    }

    /**
     * Rows of a collection this agent speaks for, or null when it speaks for all of them.
     *
     * @param string $collectionKey RT collection to ask about
     * @return ?list<string> Rows claimed by name, or null for the whole collection
     */
    public function claimedKeys(string $collectionKey): ?array
    {
        return $this->keysByCollection[$collectionKey] ?? null;
    }

    /**
     * The same claim with some of its collections taken away, as the leader's verdict left it.
     *
     * A copy rather than a mutation, because the entry the leader records and the entry the node
     * reported have to stay distinguishable: what the node believes it owns is not corrected by
     * a verdict — its agent is stopped instead — and rewriting the reported entry in place would
     * quietly turn one into the other.
     *
     * @param list<string> $collectionKeys Collections to drop from the claim
     * @return self Claim over what is left, with both axes narrowed to match
     */
    public function without(array $collectionKeys): self
    {
        $kept = array_values(array_diff($this->collectionKeys, $collectionKeys));

        return new self(
            agentId: $this->agentId,
            agentType: $this->agentType,
            agentIndex: $this->agentIndex,
            collectionKeys: $kept,
            partialCollectionKeys: array_values(array_intersect($this->partialCollectionKeys, $kept)),
            keysByCollection: array_intersect_key($this->keysByCollection, array_flip($kept)),
        );
    }

    /**
     * Serializes the entry to its wire array.
     *
     * @return array<string, mixed> Entry payload
     */
    public function toArray(): array
    {
        return [
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
            AgentConstants::FIELD_AGENT_TYPE => $this->agentType,
            AgentConstants::FIELD_AGENT_INDEX => $this->agentIndex,
            WorkerRtSourceRegisteredDTO::FIELD_COLLECTION_KEYS => $this->collectionKeys,
            WorkerRtSourceRegisteredDTO::FIELD_PARTIAL_COLLECTION_KEYS => $this->partialCollectionKeys,
            WorkerRtSourceRegisteredDTO::FIELD_KEYS_BY_COLLECTION => $this->keysByCollection,
        ];
    }

    /**
     * Restores an entry from its wire array.
     *
     * The two narrowing keys are optional, as they are on the worker's report: a peer that
     * names neither is read as owning every operation over every row, which is what a claim
     * meant before the axes existed and what the map still means by silence.
     *
     * @param array<string, mixed> $data Entry payload
     * @return static Restored entry
     * @throws PeerTransportException When the entry carries no agent id or no collection list
     */
    public static function fromArray(array $data): static
    {
        $agentIdValue = $data[AgentConstants::FIELD_AGENT_ID] ?? null;
        $agentId = is_string($agentIdValue) ? trim($agentIdValue) : null;
        if ($agentId === null || $agentId === '') {
            throw new PeerTransportException('Peer RT claim entry is missing the agent id');
        }

        $agentTypeValue = $data[AgentConstants::FIELD_AGENT_TYPE] ?? null;
        $agentType = is_string($agentTypeValue) ? trim($agentTypeValue) : null;
        if ($agentType === null || $agentType === '') {
            throw new PeerTransportException("Peer RT claim entry '{$agentId}' is missing the agent type");
        }

        $collectionsRaw = $data[WorkerRtSourceRegisteredDTO::FIELD_COLLECTION_KEYS] ?? null;
        if (!is_array($collectionsRaw)) {
            throw new PeerTransportException("Peer RT claim entry '{$agentId}' is missing the collection list");
        }

        return new static(
            agentId: $agentId,
            agentType: $agentType,
            agentIndex: PeerPlacedAgentEntry::readAgentIndex($data[AgentConstants::FIELD_AGENT_INDEX] ?? null),
            collectionKeys: self::readKeys($collectionsRaw),
            partialCollectionKeys: self::readKeys($data[WorkerRtSourceRegisteredDTO::FIELD_PARTIAL_COLLECTION_KEYS] ?? null),
            keysByCollection: self::readKeysByCollection($data[WorkerRtSourceRegisteredDTO::FIELD_KEYS_BY_COLLECTION] ?? null),
        );
    }

    /**
     * Reads one list of non-empty string keys off the wire, dropping anything else.
     *
     * @param mixed $raw Raw list value from the wire
     * @return list<string> Keys as read from the wire
     */
    private static function readKeys(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $keys = [];
        foreach ($raw as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Reads the row-claim map off the wire, dropping collections that name nothing readable.
     *
     * @param mixed $raw Raw map value from the wire
     * @return array<string, list<string>> Rows claimed by name, collection by collection
     */
    private static function readKeysByCollection(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $keysByCollection = [];
        foreach ($raw as $collectionKey => $stateIds) {
            if (is_string($collectionKey) && $collectionKey !== '' && is_array($stateIds)) {
                $keysByCollection[$collectionKey] = self::readKeys($stateIds);
            }
        }

        return $keysByCollection;
    }
}
