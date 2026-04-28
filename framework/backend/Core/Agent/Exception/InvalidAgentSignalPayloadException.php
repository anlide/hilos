<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

/**
 * Exception when an agent signal has a payload object that does not match the signal contract.
 */
class InvalidAgentSignalPayloadException extends AgentException
{
    /**
     * Creates exception for an invalid agent signal payload.
     *
     * @param string $signalName Signal name
     * @param string $expectedType Expected payload class or type
     * @param mixed $payload Actual payload
     */
    public function __construct(string $signalName, string $expectedType, mixed $payload)
    {
        parent::__construct(
            "Invalid payload type for {$signalName}: expected {$expectedType}, got " . get_debug_type($payload),
        );
    }
}
