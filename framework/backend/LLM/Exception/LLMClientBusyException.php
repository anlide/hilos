<?php

declare(strict_types=1);

namespace Hilos\LLM\Exception;

/**
 * Exception thrown when generation is started while a client is busy.
 */
class LLMClientBusyException extends LLMException
{
    /**
     * Creates busy client exception.
     */
    public function __construct()
    {
        parent::__construct('Cannot start LLM generation while another generation is in progress');
    }
}
