<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/** A leader's request for a causally current hosted set, echoed by the reporting node. */
final class PeerPlacementQueryDTO extends PeerDTO
{
    /** @var string Wire message type for the placement-query frame */
    public const string MESSAGE_TYPE = 'peer_placement_query';

    public const string FIELD_QUERY_ID = 'queryId';

    public const string FIELD_AUTHORITY_TERM = 'authorityTerm';

    /**
     * @param string $queryId Correlation id the report must echo
     * @param int $authorityTerm Authority term in which the question was issued
     * @param list<PeerDTO> $orderedPrefix Local transport frames carried in the same outbound turn as this query
     */
    public function __construct(
        public readonly string $queryId,
        public readonly int $authorityTerm,
        public readonly array $orderedPrefix = [],
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
     * Serializes the placement query to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_QUERY_ID => $this->queryId,
            self::FIELD_AUTHORITY_TERM => $this->authorityTerm,
        ];
    }

    /**
     * Restores a placement query from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored query
     * @throws PeerTransportException When the query id or authority term is malformed
     */
    public static function fromArray(array $data): static
    {
        $queryId = $data[self::FIELD_QUERY_ID] ?? null;
        $term = $data[self::FIELD_AUTHORITY_TERM] ?? null;
        if (!is_string($queryId) || trim($queryId) === '' || !is_int($term) || $term < 0) {
            throw new PeerTransportException('Peer placement query has an invalid query id or authority term');
        }

        return new static($queryId, $term);
    }
}
