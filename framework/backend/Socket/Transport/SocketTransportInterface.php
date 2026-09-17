<?php

declare(strict_types=1);

namespace Hilos\Socket\Transport;

use Hilos\Socket\Client\AbstractClient;

/**
 * The bytes-on-the-wire half of an accepted connection, under the client that reads them.
 *
 * A client speaks its protocol - HTTP, WebSocket, the worker line - and asks the transport
 * only for bytes: whether the socket carries them bare or encrypted is decided when the
 * client is created, and nothing above this seam knows which. Two questions have different
 * answers per transport, and {@see AbstractClient} asks them here instead of guessing:
 * whether an empty read ended the connection, and whether a handshake still stands between
 * the socket and the first application byte.
 */
interface SocketTransportInterface
{
    /**
     * Reads what arrived, without waiting for more.
     *
     * @param int $length Most bytes to read in one call
     * @return string|false Bytes read, '' when none are available now or the stream ended, false on a failed read
     */
    public function read(int $length): string|false;

    /**
     * Writes as much of the data as the connection takes now.
     *
     * @param string $data Bytes to send
     * @return int|false Bytes written, 0 when the same bytes must be offered again later, false on a failed write
     */
    public function write(string $data): int|false;

    /**
     * Closes the connection this transport carries.
     */
    public function close(): void;

    /**
     * Tells an empty read that ended the connection from one that found nothing yet.
     *
     * @return bool True when the peer has closed its side and nothing is left to read
     */
    public function isEndOfStream(): bool;

    /**
     * Whether application bytes must wait for a handshake to finish first.
     *
     * @return bool True until the handshake has completed
     */
    public function needsHandshake(): bool;

    /**
     * Moves the handshake one non-blocking step.
     *
     * @return int|bool True once finished, 0 while it needs another turn, false when the peer was refused
     */
    public function advanceHandshake(): int|bool;
}
