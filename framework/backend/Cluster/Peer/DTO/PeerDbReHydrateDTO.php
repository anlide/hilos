<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

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
 * It carries nothing, like {@see PeerProtectedModeLiftDTO}: the event is "the database underneath
 * you was replaced", which names no collection and no row. A receiving node opens its own local
 * {@see ReHydrateRound} over its daemon and its workers, and answers for all of them at once with
 * a {@see PeerDbReHydratedDTO}.
 */
final class PeerDbReHydrateDTO extends PeerDTO
{
    /** @var string Wire message type for the database re-hydrate frame */
    public const string MESSAGE_TYPE = 'peer_db_rehydrate';

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
        ];
    }

    /**
     * Restores a re-hydrate frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload (empty)
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
