<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\Transport\SocketTransportInterface;

/**
 * A bare socket that vouches for the name it was given, standing in for a verified TLS peer.
 *
 * The peer link refuses a hello or a welcome whose node id is not the name its transport vouches
 * for (HIL-1034). The unit suites that feed a handshake over a socket pair have no certificates
 * and need none: what they test sits above the transport. This double carries the bytes exactly
 * as the bare socket does and answers the one question TLS would have answered.
 */
final class NamedPeerTestTransport implements SocketTransportInterface
{
    /** @var PlainSocketTransport Bare socket every byte goes through */
    private PlainSocketTransport $bare;

    /** @var string Name this transport vouches for, as a verified certificate would */
    private string $peerName;

    /**
     * @param resource|object $socket Connected socket
     * @param string $peerName Node id the far end introduces itself with
     */
    public function __construct($socket, string $peerName)
    {
        $this->bare = new PlainSocketTransport($socket);
        $this->peerName = $peerName;
    }

    /**
     * @param int $length Most bytes to read in one call
     * @return string|false What the bare socket read
     */
    public function read(int $length): string|false
    {
        return $this->bare->read($length);
    }

    /**
     * @param string $data Bytes to send
     * @return int|false What the bare socket wrote
     */
    public function write(string $data): int|false
    {
        return $this->bare->write($data);
    }

    /**
     * Closes the bare socket.
     */
    public function close(): void
    {
        $this->bare->close();
    }

    /**
     * @return bool Whether the bare socket met the peer's close
     */
    public function isEndOfStream(): bool
    {
        return $this->bare->isEndOfStream();
    }

    /**
     * @return bool Always false: the handshake this double stands for is already done
     */
    public function needsHandshake(): bool
    {
        return false;
    }

    /**
     * @return int|bool Always true
     */
    public function advanceHandshake(): int|bool
    {
        return true;
    }

    /**
     * @return ?string The name given to the constructor
     */
    public function verifiedPeerName(): ?string
    {
        return $this->peerName;
    }
}
