<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Wire form of one placed agent inside a {@see PeerPlacementReportDTO}.
 *
 * Carries just the identity of a hosted agent — its type and optional index — so a node
 * can tell a fresh leader which agents it is running. Liveness and worker placement are
 * node-local and not reported; the leader only needs the set of agents to rebuild its
 * placement view. Mirrors {@see PeerNodeEntry}'s role for membership gossip.
 */
final class PeerPlacedAgentEntry extends BaseDTO
{
    /** @var string Payload key: agent type */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Payload key: agent index */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
    ) {
    }

    /**
     * Serializes the entry to its wire array.
     *
     * @return array<string, mixed> Entry payload
     */
    public function toArray(): array
    {
        return [
            self::FIELD_AGENT_TYPE => $this->agentType,
            self::FIELD_AGENT_INDEX => $this->agentIndex,
        ];
    }

    /**
     * Restores an entry from its wire array.
     *
     * @param array<string, mixed> $data Entry payload
     * @return static Restored entry
     * @throws PeerTransportException When the agent type is missing
     */
    public static function fromArray(array $data): static
    {
        $agentTypeValue = $data[self::FIELD_AGENT_TYPE] ?? null;
        $agentType = is_string($agentTypeValue) ? trim($agentTypeValue) : null;
        if ($agentType === null || $agentType === '') {
            throw new PeerTransportException('Peer placed-agent entry is missing the agent type');
        }

        return new static($agentType, self::readAgentIndex($data[self::FIELD_AGENT_INDEX] ?? null));
    }

    /**
     * Normalizes a wire agent-index value: a blank or absent value reads as null.
     *
     * @param mixed $raw Raw agent-index value from the wire
     * @return ?string Agent index, or null for a singleton agent
     */
    public static function readAgentIndex(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $index = trim((string)$raw);

        return $index === '' ? null : $index;
    }
}
