<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when out of memory (ENOMEM).
 */
class OutOfMemoryException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "Out of memory";
        parent::__construct($message, 12, $previous); // ENOMEM
    }
}
