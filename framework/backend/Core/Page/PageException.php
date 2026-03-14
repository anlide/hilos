<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\HilosException;
use Throwable;

/**
 * Base exception for page-related errors.
 */
class PageException extends HilosException
{
    /**
     * Creates page exception.
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
