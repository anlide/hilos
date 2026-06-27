<?php

declare(strict_types=1);

namespace Hilos\API\Exception;

/**
 * Exception thrown when a caller consumes a command reply before one is available.
 */
class AsyncCommandResultUnavailableException extends AsyncCommandException
{
    /**
     * Creates unavailable result exception.
     */
    public function __construct()
    {
        parent::__construct('No async command result is available to consume');
    }
}
