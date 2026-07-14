<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Placement frame a leader sends to ask a node to launch an agent of a given type.
 *
 * One-way request: the leader has already hard-checked the node's capabilities before
 * sending, so the recipient simply runs its local start path and answers with a
 * {@see PeerAgentStatusDTO} carrying the outcome. Node-selection policy that decides
 * which node receives this frame is HIL-182; this frame is the delivery mechanism.
 */
final class PeerPlaceAgentDTO extends PeerDTO
{
    /** @var string Wire message type for the place-agent frame */
    public const string MESSAGE_TYPE = 'peer_place_agent';

    /** @var string Payload key: agent type */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Payload key: agent index */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /**
     * @param string $agentType Agent type to launch
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
     * Serializes the place-agent frame to its wire array.
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
     * Restores a place-agent frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the agent type is missing
     */
    public static function fromArray(array $data): static
    {
        $agentType = trim((string)($data[self::FIELD_AGENT_TYPE] ?? ''));
        if ($agentType === '') {
            throw new PeerTransportException('Peer place-agent frame is missing the agent type');
        }

        return new static($agentType, PeerPlacedAgentEntry::readAgentIndex($data[self::FIELD_AGENT_INDEX] ?? null));
    }
}
