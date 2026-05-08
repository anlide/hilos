<?php

declare(strict_types=1);

namespace Hilos\LLM\Exception;

use Throwable;

/**
 * Exception thrown when an async LLM transport request fails.
 */
class LLMRequestException extends LLMException
{
    /**
     * Creates request failure exception.
     *
     * @param string $message Exception message
     * @param ?Throwable $previous Previous transport exception
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
