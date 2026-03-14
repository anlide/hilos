<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when invalid argument provided (EINVAL).
 */
class InvalidArgumentException extends SocketException
{
    public function __construct(string $details = '', ?Throwable $previous = null)
    {
        $message = "Invalid socket argument" . ($details ? ": {$details}" : '');
        parent::__construct($message, 22, $previous); // EINVAL
    }
}
