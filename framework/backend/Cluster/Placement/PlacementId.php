<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Exception\PeerTransportException;

/** The identity of an order, retained if another leader adopts it. */
final readonly class PlacementId
{
    public const string FIELD_LEADER_NODE_ID = 'leaderNodeId';

    public const string FIELD_TERM = 'term';

    public const string FIELD_SEQUENCE = 'sequence';

    /**
     * @param string $leaderNodeId Node that issued the order
     * @param int $term Authority term at issuance
     * @param int $sequence Positive issue sequence
     */
    public function __construct(
        public string $leaderNodeId,
        public int $term,
        public int $sequence,
    ) {
    }

    /**
     * @param mixed $raw Wire object
     * @return self Placement order identity
     * @throws PeerTransportException When the identity is malformed
     */
    public static function fromWire(mixed $raw): self
    {
        if (!is_array($raw)) {
            throw new PeerTransportException('Placement id is not an object');
        }

        $leaderNodeId = $raw[self::FIELD_LEADER_NODE_ID] ?? null;
        $term = $raw[self::FIELD_TERM] ?? null;
        $sequence = $raw[self::FIELD_SEQUENCE] ?? null;
        if (!is_string($leaderNodeId) || trim($leaderNodeId) === '' || !is_int($term) || $term < 0
            || !is_int($sequence) || $sequence <= 0) {
            throw new PeerTransportException('Placement id has an invalid leader, term or sequence');
        }

        return new self($leaderNodeId, $term, $sequence);
    }

    /** @return array<string, int|string> Wire object */
    public function toArray(): array
    {
        return [
            self::FIELD_LEADER_NODE_ID => $this->leaderNodeId,
            self::FIELD_TERM => $this->term,
            self::FIELD_SEQUENCE => $this->sequence,
        ];
    }

    /**
     * @param self $other Order to compare
     * @return bool Whether the other order was issued strictly later
     */
    public function precedes(self $other): bool
    {
        return $other->term > $this->term
            || ($other->term === $this->term && $other->leaderNodeId === $this->leaderNodeId && $other->sequence > $this->sequence);
    }

    /**
     * @param ?self $other Order to compare
     * @return bool Whether both identities name the same order
     */
    public function equals(?self $other): bool
    {
        return $other !== null && $this->leaderNodeId === $other->leaderNodeId
            && $this->term === $other->term && $this->sequence === $other->sequence;
    }
}
