<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when broken pipe (EPIPE)
 */
class BrokenPipeException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Broken pipe";
        parent::__construct($message, 32, $previous); // EPIPE
    }
}
