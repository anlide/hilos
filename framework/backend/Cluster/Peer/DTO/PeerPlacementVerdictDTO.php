<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Placement\PlacementState;

/**
 * Verdict the leader sends back on a placement ask (HIL-1041).
 *
 * The reply to {@see PeerPlacementRequestDTO}: {@see placed()} names the node that
 * already hosts the agent, and {@see notPlaced()} names why it will not — failed,
 * unplaced, or refused. It is an event, not a stored state: the asking node applies
 * it to the frames waiting at that moment and forgets it. The published view is
 * not this frame; a second failure of the same agent does not change the view, so
 * a waiter would never learn of it from a view update alone.
 */
final class PeerPlacementVerdictDTO extends PeerDTO
{
    /** @var string Wire message type for the placement-verdict frame */
    public const string MESSAGE_TYPE = 'peer_placement_verdict';

    /** @var string Payload key: agent type */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Payload key: agent index */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /** @var string Payload key: placement state */
    public const string FIELD_STATE = 'state';

    /** @var string Payload key: node id the agent was placed on */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: reason the agent was not placed */
    public const string FIELD_REASON = 'reason';

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param PlacementState $state Placement state this verdict reports
     * @param ?string $nodeId Hosting node id, or null unless placed
     * @param ?string $reason Failure reason, or null unless not placed
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly PlacementState $state,
        public readonly ?string $nodeId,
        public readonly ?string $reason,
    ) {
    }

    /**
     * Builds a placed verdict naming the node that hosts the agent.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Node id the agent was placed on
     * @return self Placed verdict
     */
    public static function placed(string $agentType, ?string $agentIndex, string $nodeId): self
    {
        return new self($agentType, $agentIndex, PlacementState::Started, $nodeId, null);
    }

    /**
     * Builds a not-placed verdict carrying the record state and the reason.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param PlacementState $state Failed, unplaced, or refused
     * @param string $reason Why the agent was not placed
     * @return self Not-placed verdict
     */
    public static function notPlaced(
        string $agentType,
        ?string $agentIndex,
        PlacementState $state,
        string $reason,
    ): self {
        return new self($agentType, $agentIndex, $state, null, $reason);
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
     * Serializes the placement-verdict frame to its wire array.
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
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_REASON => $this->reason,
        ];
    }

    /**
     * Restores a placement-verdict frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the agent type, state, node id or reason is invalid
     */
    public static function fromArray(array $data): static
    {
        $agentTypeValue = $data[self::FIELD_AGENT_TYPE] ?? null;
        $agentType = is_string($agentTypeValue) ? trim($agentTypeValue) : null;
        if ($agentType === null || $agentType === '') {
            throw new PeerTransportException('Peer placement-verdict frame is missing the agent type');
        }

        $stateValue = $data[self::FIELD_STATE] ?? null;
        $state = is_string($stateValue) ? PlacementState::tryFrom($stateValue) : null;
        if ($state === null || !self::isVerdictState($state)) {
            $shownState = is_string($stateValue) ? $stateValue : get_debug_type($stateValue);
            throw new PeerTransportException("Peer placement-verdict frame has an invalid state '{$shownState}'");
        }

        $nodeIdValue = $data[self::FIELD_NODE_ID] ?? null;
        $nodeId = is_string($nodeIdValue) ? trim($nodeIdValue) : null;
        if ($nodeId === '') {
            $nodeId = null;
        }

        $reasonValue = $data[self::FIELD_REASON] ?? null;
        $reason = is_string($reasonValue) ? trim($reasonValue) : null;
        if ($reason === '') {
            $reason = null;
        }

        if ($state === PlacementState::Started) {
            if ($nodeId === null) {
                throw new PeerTransportException('Peer placement-verdict frame is missing the node id');
            }

            return new static(
                agentType: $agentType,
                agentIndex: PeerPlacedAgentEntry::readAgentIndex($data[self::FIELD_AGENT_INDEX] ?? null),
                state: $state,
                nodeId: $nodeId,
                reason: null,
            );
        }

        if ($reason === null) {
            throw new PeerTransportException('Peer placement-verdict frame is missing the reason');
        }

        return new static(
            agentType: $agentType,
            agentIndex: PeerPlacedAgentEntry::readAgentIndex($data[self::FIELD_AGENT_INDEX] ?? null),
            state: $state,
            nodeId: null,
            reason: $reason,
        );
    }

    /**
     * Whether a placement state is one a verdict frame may carry.
     *
     * @param PlacementState $state Candidate state from the wire
     * @return bool True for started, failed, unplaced, or refused
     */
    private static function isVerdictState(PlacementState $state): bool
    {
        return $state === PlacementState::Started
            || $state === PlacementState::Failed
            || $state === PlacementState::Unplaced
            || $state === PlacementState::Refused;
    }
}
