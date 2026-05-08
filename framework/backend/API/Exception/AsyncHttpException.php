<?php

declare(strict_types=1);

namespace Hilos\API\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Base exception for async HTTP client failures.
 */
class AsyncHttpException extends HilosException
{
    /**
     * Creates async HTTP exception.
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
