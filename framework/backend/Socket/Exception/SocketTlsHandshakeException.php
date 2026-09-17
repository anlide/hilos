<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when a TLS handshake over a socket fails and the caller must be told why.
 *
 * An accepted connection whose handshake is refused is closed without a word, and needs no
 * exception; this names the refusal where somebody waits for the connection to come up.
 *
 * SCAFFOLD: not thrown yet — the server side of TLS (HIL-921) has nobody waiting on a refused
 * handshake; the first caller is the dialing side of the socket transport, where a node waits
 * for its link to come up (HIL-1034).
 */
class SocketTlsHandshakeException extends SocketException
{
    /**
     * Creates TLS handshake exception.
     *
     * @param string $reason TLS handshake failure reason
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct('Socket TLS handshake failed: ' . $reason, 0, $previous);
    }
}
