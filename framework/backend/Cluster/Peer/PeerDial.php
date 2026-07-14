<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

/**
 * Mutable per-seed dial state owned by {@see PeerServer}.
 *
 * Tracks one seed through the non-blocking connect state machine: idle (waiting
 * for the next attempt), connecting (socket opened, awaiting writability), or
 * linked (promoted to a {@see PeerLink}). It is an internal state holder, not a
 * value object — the server mutates it in place on each tick.
 */
final class PeerDial
{
    /** @var resource|object|null In-flight connecting socket, or null when idle or linked */
    public mixed $socket = null;

    /** @var bool True while a non-blocking connect is in progress */
    public bool $connecting = false;

    /** @var float Earliest microtime the next dial attempt may start */
    public float $nextAttemptAt = 0.0;

    /** @var float Microtime the current connect attempt started (for the connect timeout) */
    public float $connectStartedAt = 0.0;

    /** @var ?PeerLink Established link, or null while not connected */
    public ?PeerLink $link = null;

    /**
     * @var ?string Remote node id learned once this seed first handshaked, or null until then.
     *
     * Kept even after the dialed link is dropped so the seed is not re-dialed while
     * the same peer is already reachable over an inbound link.
     */
    public ?string $remoteNodeId = null;

    /**
     * @param PeerAddress $seed Seed address this dial targets
     */
    public function __construct(
        public readonly PeerAddress $seed,
    ) {
    }
}
