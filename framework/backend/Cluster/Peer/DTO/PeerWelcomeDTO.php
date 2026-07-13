<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

/**
 * Handshake frame the accepting node sends back to acknowledge a hello.
 */
final class PeerWelcomeDTO extends PeerDTO
{
    /** @var string Wire message type for the welcome frame */
    public const string MESSAGE_TYPE = 'peer_welcome';

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
