<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

/**
 * Handshake frame a dialing node sends first to introduce itself to a peer.
 */
final class PeerHelloDTO extends PeerHandshakeDTO
{
    /** @var string Wire message type for the hello frame */
    public const string MESSAGE_TYPE = 'peer_hello';

    /**
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }
}
