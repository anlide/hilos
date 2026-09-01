<?php

declare(strict_types=1);

namespace Hilos\API\Exception;

use Throwable;

/**
 * Exception thrown when the TLS handshake of an async HTTP request fails.
 */
class AsyncHttpTlsHandshakeException extends AsyncHttpException
{
    /**
     * Creates TLS handshake exception.
     *
     * @param string $reason TLS handshake failure reason
     * @param ?Throwable $previous Previous exception
     */
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct('Async HTTP TLS handshake failed: ' . $reason, previous: $previous);
    }
}
