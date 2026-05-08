<?php

declare(strict_types=1);

namespace Hilos\API\Exception;

/**
 * Exception thrown when a request is started while the client is busy.
 */
class AsyncHttpBusyException extends AsyncHttpException
{
    /**
     * Creates busy client exception.
     */
    public function __construct()
    {
        parent::__construct('Cannot start async HTTP request while another request is in progress');
    }
}
