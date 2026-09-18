<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when a TLS handshake over a socket fails and the caller must be told why.
 *
 * Thrown by a transport that verifies its peer - the dialing side, and the accepting side given
 * a trust file - where everyone refused was meant to be one of us, and the operator must see
 * either the misconfigured node or the stranger. The message reads
 * "Socket TLS handshake failed: <ip:port>: <OpenSSL's reason>". A public port verifies nobody,
 * and its refused handshake closes without a word and without this exception.
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
