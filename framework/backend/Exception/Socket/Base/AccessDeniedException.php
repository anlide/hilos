<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when permission denied (EACCES)
 */
class AccessDeniedException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Permission denied";
        parent::__construct($message, 13, $previous); // EACCES
    }
}
