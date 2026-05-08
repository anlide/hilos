<?php

declare(strict_types=1);

namespace Hilos\API\Exception;

/**
 * Exception thrown when a caller consumes a result before one is available.
 */
class AsyncHttpResultUnavailableException extends AsyncHttpException
{
    /**
     * Creates unavailable result exception.
     */
    public function __construct()
    {
        parent::__construct('No async HTTP result is available to consume');
    }
}
