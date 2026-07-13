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
    public const int VERSION = 1;

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
}
