<?php

declare(strict_types=1);

namespace Hilos\LLM\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Base exception for LLM client failures.
 */
class LLMException extends HilosException
{
    /**
     * Creates LLM exception.
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
