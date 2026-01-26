<?php

declare(strict_types=1);

namespace Hilos\Exception;

use Exception;
use Throwable;

/**
 * Base exception for page-related errors
 */
class PageException extends Exception
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
