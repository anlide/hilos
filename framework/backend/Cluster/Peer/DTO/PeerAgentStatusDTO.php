<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Placement\PlacementState;

/**
 * Placement frame a node sends back to the leader reporting the outcome of a placement.
 *
 * The reply to {@see PeerPlaceAgentDTO} / {@see PeerStopAgentDTO}: {@see PlacementState::Started}
 * carries the worker id the agent landed on, {@see PlacementState::Failed} carries the
 * reason, and {@see PlacementState::Stopped} confirms a revoke. The leader folds it into
 * its placement view. The provisional {@see PlacementState::Placing} is leader-local and
 * never travels the wire.
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

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param PlacementState $state Reported placement state
     * @param ?int $workerId Worker id the agent landed on, or null unless started
     * @param ?string $error Failure reason, or null unless failed
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly PlacementState $state,
        public readonly ?int $workerId,
        public readonly ?string $error,
    ) {
    }

    /**
     * Builds a started-state status carrying the worker id the agent landed on.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param int $workerId Worker id the agent landed on
     * @return self Started status
     */
    public static function started(string $agentType, ?string $agentIndex, int $workerId): self
    {
        return new self($agentType, $agentIndex, PlacementState::Started, $workerId, null);
    }

    /**
     * Builds a failed-state status carrying the reason the placement could not run.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $error Failure reason
     * @return self Failed status
     */
    public static function failed(string $agentType, ?string $agentIndex, string $error): self
    {
        return new self($agentType, $agentIndex, PlacementState::Failed, null, $error);
    }

    /**
     * Builds a stopped-state status confirming a revoke.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return self Stopped status
     */
    public static function stopped(string $agentType, ?string $agentIndex): self
    {
        return new self($agentType, $agentIndex, PlacementState::Stopped, null, null);
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
        ];
    }

    /**
     * Restores an agent-status frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the agent type is missing or the state is invalid
     */
    public static function fromArray(array $data): static
    {
        $agentType = trim((string)($data[self::FIELD_AGENT_TYPE] ?? ''));
        if ($agentType === '') {
            throw new PeerTransportException('Peer agent-status frame is missing the agent type');
        }

        $state = PlacementState::tryFrom((string)($data[self::FIELD_STATE] ?? ''));
        if ($state === null) {
            throw new PeerTransportException("Peer agent-status frame has an invalid state '" . (string)($data[self::FIELD_STATE] ?? '') . "'");
        }

        $workerId = $data[self::FIELD_WORKER_ID] ?? null;
        $error = $data[self::FIELD_ERROR] ?? null;

        return new static(
            agentType: $agentType,
            agentIndex: PeerPlacedAgentEntry::readAgentIndex($data[self::FIELD_AGENT_INDEX] ?? null),
            state: $state,
            workerId: $workerId !== null ? (int)$workerId : null,
            error: $error !== null ? (string)$error : null,
        );
    }
}
