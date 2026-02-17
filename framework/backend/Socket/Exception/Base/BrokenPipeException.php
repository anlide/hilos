<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
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
