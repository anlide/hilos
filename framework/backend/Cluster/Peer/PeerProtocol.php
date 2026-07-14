<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

/**
 * Wire-protocol version for the inter-daemon peer channel.
 *
 * A peer declares this version in its handshake; a link whose remote reports a
 * different version is rejected rather than risking a frame-format mismatch.
 * Bump it whenever the peer frame shape or handshake sequence changes in a way
 * an older node could not parse.
 */
final class PeerProtocol
{
    /** @var int Current peer wire-protocol version */
    public const int VERSION = 3;

    /**
     * Reports whether a remote-declared protocol version can share this channel.
     *
     * @param int $version Protocol version reported by the remote peer
     * @return bool True when the version matches this node's protocol
     */
    public static function isCompatible(int $version): bool
    {
        return $version === self::VERSION;
    }

    /**
     * Decides which of two duplicate links to the same peer survives a collapse.
     *
     * When two nodes list each other as seeds and bootstrap at the same time they
     * dial each other simultaneously, so each ends up with two links to the same
     * peer — one it dialed, one it accepted. Both nodes must independently keep the
     * same connection, or they would either drop both or keep both. The shared rule
     * is to keep the link dialed by the lexicographically smaller node id: it is
     * deterministic, symmetric across the pair, and needs no coordination. Returns
     * true when the link this node dialed is the survivor.
     *
     * @param string $localNodeId This node's id
     * @param string $remoteNodeId The peer's id
     * @return bool True when the locally-dialed link is the one to keep
     */
    public static function dialedLinkWinsTieBreak(string $localNodeId, string $remoteNodeId): bool
    {
        return $localNodeId < $remoteNodeId;
    }
}
