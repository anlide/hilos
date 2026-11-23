<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Placement\PlacementId;
use Hilos\Cluster\Placement\PlacementRecord;

/**
 * Placement frame a leader sends to ask a node to stop a placed agent.
 *
 * The mirror of {@see PeerPlaceAgentDTO}: the recipient runs its local stop path and
 * answers with a {@see PeerAgentStatusDTO} in the stopped state. A move or rebalance is
 * a stop followed by a place; the rebalance policy itself is HIL-182 / HIL-183.
 */
final class PeerStopAgentDTO extends PeerDTO
{
    /** @var string Wire message type for the stop-agent frame */
    public const string MESSAGE_TYPE = 'peer_stop_agent';

    /** @var string Payload key: agent type */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Payload key: agent index */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    public const string FIELD_AUTHORITY_TERM = PeerPlacementQueryDTO::FIELD_AUTHORITY_TERM;

    public const string FIELD_STOP_ID = 'stopId';

    public const string FIELD_PLACEMENT_ID = PlacementRecord::FIELD_PLACEMENT_ID;

    /**
     * @param string $agentType Agent type to stop
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param int $authorityTerm Term when the command was issued
     * @param string $stopId Correlation of this logical stop
     * @param ?PlacementId $placementId Assignment to stop, absent for an ordinary agent
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly int $authorityTerm,
        public readonly string $stopId,
        public readonly ?PlacementId $placementId = null,
    ) {
    }

    /** @return PeerAgentStatusDTO Receipt carrying the exact command's coordinates */
    public function stoppedStatus(): PeerAgentStatusDTO
    {
        return PeerAgentStatusDTO::stopped($this->agentType, $this->agentIndex, $this->placementId, $this->authorityTerm, $this->stopId);
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
     * Serializes the stop-agent frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_AGENT_TYPE => $this->agentType,
            self::FIELD_AGENT_INDEX => $this->agentIndex,
            self::FIELD_AUTHORITY_TERM => $this->authorityTerm,
            self::FIELD_STOP_ID => $this->stopId,
        ] + ($this->placementId === null ? [] : [self::FIELD_PLACEMENT_ID => $this->placementId->toArray()]);
    }

    /**
     * Restores a stop-agent frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the agent address, authority or correlation is malformed
     */
    public static function fromArray(array $data): static
    {
        $agentTypeValue = $data[self::FIELD_AGENT_TYPE] ?? null;
        $agentType = is_string($agentTypeValue) ? trim($agentTypeValue) : null;
        if ($agentType === null || $agentType === '') {
            throw new PeerTransportException('Peer stop-agent frame is missing the agent type');
        }

        return new static(
            $agentType,
            PeerPlacedAgentEntry::readAgentIndex($data[self::FIELD_AGENT_INDEX] ?? null),
            self::readAuthorityTerm($data),
            self::readCorrelationId($data, self::FIELD_STOP_ID),
            array_key_exists(self::FIELD_PLACEMENT_ID, $data) ? PlacementId::fromWire($data[self::FIELD_PLACEMENT_ID]) : null,
        );
    }
}
