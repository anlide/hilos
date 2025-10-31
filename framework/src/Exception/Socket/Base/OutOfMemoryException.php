<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when out of memory (ENOMEM)
 */
class OutOfMemoryException extends SocketException implements Throwable
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Out of memory";
        parent::__construct($message, 12, $previous); // ENOMEM
    }
}

