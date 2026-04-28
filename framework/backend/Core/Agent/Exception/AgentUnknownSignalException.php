<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

/**
 * Exception when unknown or unsupported agent signal name is received.
 */
class AgentUnknownSignalException extends AgentException
{
    /**
     * Creates exception for an unsupported agent signal.
     *
     * @param string $signalName Signal name
     */
    public function __construct(string $signalName)
    {
        parent::__construct("Unknown agent signal: {$signalName}");
    }
}
