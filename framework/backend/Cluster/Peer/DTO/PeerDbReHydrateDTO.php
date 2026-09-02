<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Database\ReHydrateRound;

/**
 * Peer frame the initiator node broadcasts after replacing the database under the cluster.
 *
 * The nodes of a cluster share one database, so a restore run on any of them leaves the others
 * answering out of caches of a database that no longer exists - "looks alive, replies with a
 * fiction", one storey up from the same problem inside a single node. This is the announcement
 * that reaches them, sent with {@see PeerServer::broadcastToMasters}.
 *
 * It carries one field, and it is not about the database: the number the announcing node opened
 * its own round under (HIL-694). The event itself still names no collection and no row. A
 * receiving node opens its own local {@see ReHydrateRound} over its daemon and its workers under
 * its own number, keeps this one as an opaque token, and returns it with the answer it sends back
 * in a {@see PeerDbReHydratedDTO} - which is how the announcer knows the answer is to this
 * announcement and not to the one before it.
 */
final class PeerDbReHydrateDTO extends PeerDTO
{
    /** @var string Wire message type for the database re-hydrate frame */
    public const string MESSAGE_TYPE = 'peer_db_rehydrate';

    /** @var string Field key carrying the announcing node's round number */
    public const string FIELD_ROUND = 'round';

    /**
     * @param int $round Number the announcing node opened its round under, echoed back in the answer
     */
    public function __construct(
        public readonly int $round,
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
     * Serializes the re-hydrate frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_ROUND => $this->round,
        ];
    }

    /**
     * Restores a re-hydrate frame from its wire array.
     *
     * An announcement with no number on it is refused outright, the way a report with no node on
     * it is: the number is the address the answer goes back to, and an announcement nobody can
     * answer is not one. The worker link is tolerant here and this one is not, because a frame
     * that lost its number there is dropped by the round it reaches, while here it would open a
     * round whose answer has nowhere to return to.
     *
     * @param array<string, mixed> $data Frame payload (round)
     * @return static Restored frame
     * @throws PeerTransportException When the announcement carries no round number
     */
    public static function fromArray(array $data): static
    {
        $round = $data[self::FIELD_ROUND] ?? null;
        if (!is_int($round) || $round < 1) {
            throw new PeerTransportException('Peer db re-hydrate announcement carries no round number');
        }

        return new static(round: $round);
    }
}
