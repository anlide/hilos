<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Constants\AgentConstants;

/**
 * Immutable placement record: the chosen node, if any, and the agent's last-known state.
 *
 * Held both in the leader-side {@see PlacementRegistry} (the cluster-wide view an
 * elected leader keeps) and in each node's own hosted set (what this node was told to
 * run). It is a value object, replaced wholesale on a state change rather than mutated;
 * {@see agentId()} derives the same "type" / "type:index" key the agent manager uses.
 *
 * Placing, Started and Refused name a real, nonempty node. Failed, Unplaced and Stopped may
 * carry null when no node was chosen, or retain the last attempted node; that history is no
 * address or reservation. Only Placing and Started identify a current host.
 * In the leader registry, Stopped is an explicit cancellation tombstone: a real node still owes
 * stop-ack, and null means the stop is confirmed. It has no diagnosis or retry and remains tracked
 * until new explicit demand; ordinary idle stops leave no record. Complete snapshots carry
 * tombstones across leadership changes so inherited cancellation suppresses stale live reports.
 * The order id can survive in a record without a host. It identifies the revoked assignment or
 * preserves the issuance floor across rebuild while an original frame still waits for later work.
 *
 * A record of an agent that runs nowhere because its placement failed — {@see PlacementState::Failed}
 * or {@see PlacementState::Unplaced} — also says why (HIL-447): the human text of the failure and
 * the gate's refusals per node, the latter empty when the failure did not come from the gate. A
 * record of any other state carries neither.
 */
final class PlacementRecord
{
    /** @var string Wire key: agent type */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Wire key: agent index, null for a singleton */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /** @var string Wire key: chosen or last attempted node, null when none */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Wire key: placement state */
    public const string FIELD_STATE = 'state';

    /** @var string Wire key: failure text, null when absent */
    public const string FIELD_REASON = 'reason';

    /** @var string Wire key: gate refusals behind a failed or unplaced record */
    public const string FIELD_REFUSALS = 'refusals';

    public const string FIELD_PLACEMENT_ID = 'placementId';

    public const string FIELD_WORKER_STARTED = 'workerStarted';

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param ?string $nodeId Chosen or last attempted node id, or null when none was chosen
     * @param PlacementState $state Last-known placement state
     * @param ?string $reason Why the placement failed, or null when it did not or nobody said
     * @param list<PlacementRefusal> $refusals Gate refusals behind the failure; empty for any other failure
     * @param ?PlacementId $placementId Identity of the order
     * @param bool $workerStarted Whether the worker confirmed this exact order
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly ?string $nodeId,
        public readonly PlacementState $state,
        public readonly ?string $reason = null,
        public readonly array $refusals = [],
        public readonly ?PlacementId $placementId = null,
        public readonly bool $workerStarted = false,
    ) {
    }

    /**
     * Every key is required, including nullable fields; malformed records are refused whole.
     *
     * @param array<string, mixed> $data Record payload decoded as an associative array
     * @return self Restored record
     * @throws PeerTransportException When a field is missing, mistyped, blank, or inconsistent with the state
     */
    public static function fromArray(array $data): self
    {
        foreach ([
            self::FIELD_AGENT_TYPE, self::FIELD_AGENT_INDEX, self::FIELD_NODE_ID,
            self::FIELD_STATE, self::FIELD_REASON, self::FIELD_REFUSALS,
        ] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new PeerTransportException("Placement record is missing '{$field}'");
            }
        }

        $agentType = $data[self::FIELD_AGENT_TYPE];
        if (!is_string($agentType) || trim($agentType) === '') {
            throw new PeerTransportException('Placement record has no non-blank agent type');
        }

        $agentIndex = $data[self::FIELD_AGENT_INDEX];
        if ($agentIndex !== null && (!is_string($agentIndex) || trim($agentIndex) === '')) {
            throw new PeerTransportException('Placement record has a malformed agent index');
        }

        $nodeId = $data[self::FIELD_NODE_ID];
        if ($nodeId !== null && (!is_string($nodeId) || trim($nodeId) === '')) {
            throw new PeerTransportException('Placement record has a malformed node id');
        }

        $stateValue = $data[self::FIELD_STATE];
        $state = is_string($stateValue) ? PlacementState::tryFrom($stateValue) : null;
        if ($state === null) {
            throw new PeerTransportException('Placement record has an unknown state');
        }
        if ($nodeId === null && ($state->hostsAgent() || $state === PlacementState::Refused)) {
            throw new PeerTransportException("Placement record '{$state->value}' names no node");
        }

        $reason = $data[self::FIELD_REASON];
        if ($reason !== null && !is_string($reason)) {
            throw new PeerTransportException('Placement record has a malformed reason');
        }

        $rawRefusals = $data[self::FIELD_REFUSALS];
        if (!is_array($rawRefusals) || !array_is_list($rawRefusals)) {
            throw new PeerTransportException('Placement record refusals are not a list');
        }

        $refusals = [];
        foreach ($rawRefusals as $rawRefusal) {
            if (!is_array($rawRefusal)) {
                throw new PeerTransportException('Placement record carries a refusal that is not an object');
            }
            $refusals[] = PlacementRefusal::fromArray($rawRefusal);
        }

        if ($state !== PlacementState::Failed && $state !== PlacementState::Unplaced && ($reason !== null || $refusals !== [])) {
            throw new PeerTransportException("Placement record '{$state->value}' carries a failure diagnosis");
        }

        $placementId = array_key_exists(self::FIELD_PLACEMENT_ID, $data)
            ? PlacementId::fromWire($data[self::FIELD_PLACEMENT_ID]) : null;

        return new self($agentType, $agentIndex, $nodeId, $state, $reason, $refusals, $placementId,
            self::readWorkerStarted($data, $state, $placementId));
    }

    /**
     * @param array<string, mixed> $data Wire payload that can carry a worker start confirmation
     * @param ?PlacementState $state Wire placement state
     * @param ?PlacementId $placementId Placement order being confirmed
     * @return bool Whether the worker confirmed the exact order
     * @throws PeerTransportException When the start confirmation is malformed or inconsistent
     */
    public static function readWorkerStarted(array $data, ?PlacementState $state, ?PlacementId $placementId): bool
    {
        if (!array_key_exists(self::FIELD_WORKER_STARTED, $data)) {
            return false;
        }
        $value = $data[self::FIELD_WORKER_STARTED];
        if (!is_bool($value) || ($value && ($state !== PlacementState::Started || $placementId === null))) {
            throw new PeerTransportException('Placement worker start confirmation is invalid');
        }

        return $value;
    }

    /**
     *
     * @return array<string, mixed> Record payload, with explicit nullable keys
     */
    public function toArray(): array
    {
        return [
            self::FIELD_AGENT_TYPE => $this->agentType,
            self::FIELD_AGENT_INDEX => $this->agentIndex,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_STATE => $this->state->value,
            self::FIELD_REASON => $this->reason,
            self::FIELD_REFUSALS => array_map(static fn(PlacementRefusal $refusal): array => $refusal->toArray(), $this->refusals),
        ] + ($this->placementId === null ? [] : [self::FIELD_PLACEMENT_ID => $this->placementId->toArray()])
            + ($this->workerStarted ? [self::FIELD_WORKER_STARTED => true] : []);
    }

    /**
     * Returns the agent id ("type" or "type:index") this record keys on.
     *
     * @return string Agent id
     */
    public function agentId(): string
    {
        return $this->agentIndex !== null
            ? $this->agentType . AgentConstants::ID_SEPARATOR . $this->agentIndex
            : $this->agentType;
    }

    /**
     * Returns a copy of this record in a new placement state.
     *
     * The diagnosis survives only into a state that still runs nowhere for want of a placement,
     * {@see PlacementState::Failed} or {@see PlacementState::Unplaced}; any other state drops it,
     * so a record that is placing, started or refused never reads as a failure. A NEW failure is
     * not a state change of this kind: it is a new record built with its own reason.
     *
     * @param PlacementState $state New placement state
     * @return self Record in the new state
     */
    public function withState(PlacementState $state): self
    {
        if ($state !== PlacementState::Failed && $state !== PlacementState::Unplaced) {
            return new self($this->agentType, $this->agentIndex, $this->nodeId, $state, placementId: $this->placementId,
                workerStarted: $state === PlacementState::Started && $this->workerStarted);
        }

        return new self($this->agentType, $this->agentIndex, $this->nodeId, $state, $this->reason, $this->refusals, $this->placementId);
    }

    /**
     * Whether another record says exactly what this one says: the same agent on the same node in
     * the same state, failed for the same reason with the same refusals.
     *
     * Compared by value, field by field, never by object identity.
     *
     * @param self $other Record to compare with
     * @return bool True when every field is equal
     */
    public function sameAs(self $other): bool
    {
        if ($this->agentType !== $other->agentType
            || $this->agentIndex !== $other->agentIndex
            || $this->nodeId !== $other->nodeId
            || $this->state !== $other->state
            || $this->reason !== $other->reason
            || $this->workerStarted !== $other->workerStarted
            || ($this->placementId === null ? $other->placementId !== null : !$this->placementId->equals($other->placementId))
            || count($this->refusals) !== count($other->refusals)
        ) {
            return false;
        }

        foreach ($this->refusals as $position => $refusal) {
            if (!$refusal->equals($other->refusals[$position])) {
                return false;
            }
        }

        return true;
    }
}
