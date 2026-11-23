<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Placement\PlacementId;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRefusal;
use Hilos\Cluster\Placement\PlacementState;

/**
 * Placement frame a node sends back to the leader reporting the outcome of a placement.
 *
 * The reply to {@see PeerPlaceAgentDTO} / {@see PeerStopAgentDTO}: {@see PlacementState::Started}
 * carries the worker id the agent landed on, {@see PlacementState::Failed} carries the
 * reason, and {@see PlacementState::Stopped} confirms a revoke. The leader folds it into
 * its placement view. The provisional {@see PlacementState::Placing} is leader-local and
 * never travels the wire.
 *
 * A failure also carries the gate's refusals (HIL-447) when it was the node's own end of the
 * placement gate that refused — one refusal, for this node — and an empty list for any other
 * failure; the error stays the text a person reads. Started and stopped carry an empty list, and a
 * frame that claims otherwise is refused.
 */
final class PeerAgentStatusDTO extends PeerDTO
{
    /** @var string Wire message type for the agent-status frame */
    public const string MESSAGE_TYPE = 'peer_agent_status';

    /** @var string Payload key: agent type */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Payload key: agent index */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /** @var string Payload key: placement state */
    public const string FIELD_STATE = 'state';

    /** @var string Payload key: worker id the agent landed on */
    public const string FIELD_WORKER_ID = 'workerId';

    /** @var string Payload key: failure reason */
    public const string FIELD_ERROR = 'error';

    /** @var string Payload key: placement-gate refusals behind a failure, always a list */
    public const string FIELD_REFUSALS = 'refusals';

    public const string FIELD_PLACEMENT_ID = PlacementRecord::FIELD_PLACEMENT_ID;

    public const string FIELD_AUTHORITY_TERM = PeerPlacementQueryDTO::FIELD_AUTHORITY_TERM;

    public const string FIELD_STOP_ID = PeerStopAgentDTO::FIELD_STOP_ID;

    public const string FIELD_WORKER_STARTED = PlacementRecord::FIELD_WORKER_STARTED;

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param PlacementState $state Reported placement state
     * @param ?int $workerId Worker id the agent landed on, or null unless started
     * @param ?string $error Failure reason, or null unless failed
     * @param list<PlacementRefusal> $refusals Gate refusals behind a failure; empty otherwise
     * @param ?PlacementId $placementId Identity of the order this status answers
     * @param ?int $authorityTerm Term under which the host sends this status
     * @param bool $workerStarted Whether the exact worker attempt confirmed its start
     * @param ?string $stopId Correlation of the explicit Stop, absent for a spontaneous stop
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly PlacementState $state,
        public readonly ?int $workerId,
        public readonly ?string $error,
        public readonly array $refusals = [],
        public readonly ?PlacementId $placementId = null,
        public readonly ?int $authorityTerm = null,
        public readonly bool $workerStarted = false,
        public readonly ?string $stopId = null,
    ) {
    }

    /**
     * Builds a started-state status carrying the worker id the agent landed on.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param int $workerId Worker id the agent landed on
     * @param ?PlacementId $placementId Placement order being answered
     * @param ?int $authorityTerm Current term
     * @param bool $workerStarted Whether the exact worker attempt confirmed its start
     * @return self Started status
     */
    public static function started(
        string $agentType,
        ?string $agentIndex,
        int $workerId,
        ?PlacementId $placementId = null,
        ?int $authorityTerm = null,
        bool $workerStarted = false,
    ): self {
        return new self($agentType, $agentIndex, PlacementState::Started, $workerId, null, [], $placementId, $authorityTerm, $workerStarted);
    }

    /**
     * Builds a failed-state status carrying the reason the placement could not run.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $error Failure reason
     * @param list<PlacementRefusal> $refusals Gate refusals when the gate refused; empty for any other failure
     * @param ?PlacementId $placementId Placement order being answered
     * @param ?int $authorityTerm Current term
     * @return self Failed status
     */
    public static function failed(
        string $agentType,
        ?string $agentIndex,
        string $error,
        array $refusals = [],
        ?PlacementId $placementId = null,
        ?int $authorityTerm = null,
    ): self {
        return new self($agentType, $agentIndex, PlacementState::Failed, null, $error, $refusals, $placementId, $authorityTerm);
    }

    /**
     * Builds a stopped-state status confirming a revoke.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param ?PlacementId $placementId Exact managed order that stopped
     * @param ?int $authorityTerm Current term, absent on an ordinary spontaneous stop
     * @param ?string $stopId Explicit command being acknowledged
     * @return self Stopped status
     */
    public static function stopped(
        string $agentType,
        ?string $agentIndex,
        ?PlacementId $placementId = null,
        ?int $authorityTerm = null,
        ?string $stopId = null,
    ): self {
        return new self(
            $agentType, $agentIndex, PlacementState::Stopped, null, null,
            placementId: $placementId, authorityTerm: $authorityTerm, stopId: $stopId,
        );
    }

    /**
     * @param ?PlacementId $order Assignment reported by this status, if managed
     * @return bool Whether both sides refer to the same order or are both ordinary
     */
    public function answersOrder(?PlacementId $order): bool
    {
        return $order === null ? $this->placementId === null : $order->equals($this->placementId);
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
     * Serializes the agent-status frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_AGENT_TYPE => $this->agentType,
            self::FIELD_AGENT_INDEX => $this->agentIndex,
            self::FIELD_STATE => $this->state->value,
            self::FIELD_WORKER_ID => $this->workerId,
            self::FIELD_ERROR => $this->error,
            self::FIELD_REFUSALS => array_map(
                static fn(PlacementRefusal $refusal): array => $refusal->toArray(),
                $this->refusals,
            ),
        ] + ($this->placementId === null ? [] : [self::FIELD_PLACEMENT_ID => $this->placementId->toArray()])
            + ($this->authorityTerm === null ? [] : [self::FIELD_AUTHORITY_TERM => $this->authorityTerm])
            + ($this->workerStarted ? [self::FIELD_WORKER_STARTED => true] : [])
            + ($this->stopId === null ? [] : [self::FIELD_STOP_ID => $this->stopId]);
    }

    /**
     * Restores an agent-status frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the agent type is missing, the state is invalid, or the refusals are malformed or not a failure's
     */
    public static function fromArray(array $data): static
    {
        $agentTypeValue = $data[self::FIELD_AGENT_TYPE] ?? null;
        $agentType = is_string($agentTypeValue) ? trim($agentTypeValue) : null;
        if ($agentType === null || $agentType === '') {
            throw new PeerTransportException('Peer agent-status frame is missing the agent type');
        }

        $stateValue = $data[self::FIELD_STATE] ?? null;
        $state = is_string($stateValue) ? PlacementState::tryFrom($stateValue) : null;
        if ($state === null) {
            $shownState = is_string($stateValue) ? $stateValue : get_debug_type($stateValue);
            throw new PeerTransportException("Peer agent-status frame has an invalid state '{$shownState}'");
        }

        $refusals = self::readPlacementRefusals($data, self::FIELD_REFUSALS, 'agent-status');
        if ($refusals !== [] && $state !== PlacementState::Failed) {
            throw new PeerTransportException("Peer agent-status frame carries refusals in state '{$state->value}'");
        }

        $hasId = array_key_exists(self::FIELD_PLACEMENT_ID, $data);
        $hasTerm = array_key_exists(self::FIELD_AUTHORITY_TERM, $data);
        $placementId = $hasId ? PlacementId::fromWire($data[self::FIELD_PLACEMENT_ID]) : null;
        $term = $hasTerm ? self::readAuthorityTerm($data) : null;
        $stopId = array_key_exists(self::FIELD_STOP_ID, $data) ? self::readCorrelationId($data, self::FIELD_STOP_ID) : null;
        if ($hasTerm !== ($hasId || $stopId !== null)
            || ($stopId !== null && $state !== PlacementState::Stopped)
            || ($hasId && !in_array($state, [PlacementState::Started, PlacementState::Failed, PlacementState::Stopped], true))) {
            throw new PeerTransportException('Peer agent-status frame has invalid placement authority');
        }
        if ($state !== PlacementState::Started && array_key_exists(self::FIELD_WORKER_STARTED, $data)) {
            throw new PeerTransportException('Peer agent-status frame carries a worker started flag outside Started');
        }

        $workerId = $data[self::FIELD_WORKER_ID] ?? null;
        if ($hasTerm && ($state === PlacementState::Started ? !is_int($workerId) : $workerId !== null)) {
            throw new PeerTransportException('Peer agent-status frame has an invalid worker address for its placement state');
        }
        $error = $data[self::FIELD_ERROR] ?? null;

        return new static(
            agentType: $agentType,
            agentIndex: PeerPlacedAgentEntry::readAgentIndex($data[self::FIELD_AGENT_INDEX] ?? null),
            state: $state,
            workerId: $workerId !== null ? (int)$workerId : null,
            error: $error !== null ? (string)$error : null,
            refusals: $refusals,
            placementId: $placementId,
            authorityTerm: $term,
            workerStarted: PlacementRecord::readWorkerStarted($data, $state, $placementId),
            stopId: $stopId,
        );
    }
}
