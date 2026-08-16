<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Hilos\Core\Exception\MalformedInput;
use Throwable;

/**
 * Exception when an agent signal has a payload object that does not match the signal contract.
 *
 * Carries {@see MalformedInput}: the payload arrived and refused to become the DTO the
 * signal declares, which is the parsing failure of an agent frame and not a fault of the
 * agent that received it.
 */
class InvalidAgentSignalPayloadException extends AgentException implements MalformedInput
{
    /**
     * Creates exception for an invalid agent signal payload.
     *
     * The hydration failure reason is appended to the message, not only chained as
     * previous: agent errors reach the log through a single message string.
     *
     * @param string $signalName Signal name
     * @param string $expectedType Expected payload class or type
     * @param mixed $payload Actual payload
     * @param ?Throwable $previous Hydration failure that rejected the payload
     */
    public function __construct(string $signalName, string $expectedType, mixed $payload, ?Throwable $previous = null)
    {
        $message = "Invalid payload type for {$signalName}: expected {$expectedType}, got " . get_debug_type($payload);
        if ($previous !== null) {
            $message .= '; fromArray failed: ' . get_class($previous) . ': ' . $previous->getMessage();
        }

        parent::__construct($message, 0, $previous);
    }
}
