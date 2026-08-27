<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Placement frame a node sends the leader to ask that an agent be placed somewhere.
 *
 * The inverse direction of {@see PeerPlaceAgentDTO}: that one is the leader telling a node to
 * run an agent, this one is a node telling the leader that an agent is wanted at all. It exists
 * because an instance agent is started by being addressed (HIL-628), and the frame that
 * addresses it can land on any node — while only the leader owns the placement view and may
 * decide where it runs.
 *
 * Carries the agent alone and no node id: naming a target would be this sender picking the host,
 * which is the leader's placement policy to decide. There is no reply frame either — the
 * placement it triggers reaches the asking node as an ordinary view update, and the frame that
 * provoked the request is dropped (its delivery is HIL-629).
 */
final class PeerPlacementRequestDTO extends PeerDTO
{
    /** @var string Wire message type for the placement-request frame */
    public const string MESSAGE_TYPE = 'peer_placement_request';

    /** @var string Payload key: agent type */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Payload key: agent index */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /**
     * @param string $agentType Agent type that wants placing
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
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
     * Serializes the placement-request frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_AGENT_TYPE => $this->agentType,
            self::FIELD_AGENT_INDEX => $this->agentIndex,
        ];
    }

    /**
     * Restores a placement-request frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the agent type is missing
     */
    public static function fromArray(array $data): static
    {
        $agentTypeValue = $data[self::FIELD_AGENT_TYPE] ?? null;
        $agentType = is_string($agentTypeValue) ? trim($agentTypeValue) : null;
        if ($agentType === null || $agentType === '') {
            throw new PeerTransportException('Peer placement-request frame is missing the agent type');
        }

        return new static($agentType, PeerPlacedAgentEntry::readAgentIndex($data[self::FIELD_AGENT_INDEX] ?? null));
    }
}
