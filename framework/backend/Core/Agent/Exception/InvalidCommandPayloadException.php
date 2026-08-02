<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Throwable;

/**
 * Exception when a routed CLI command has a payload that does not match the command contract.
 */
class InvalidCommandPayloadException extends AgentException
{
    /**
     * Creates exception for an invalid command payload.
     *
     * The hydration failure reason is appended to the message, not only chained as
     * previous: agent errors reach the log through a single message string.
     *
     * @param string $command Command name
     * @param string $expectedType Expected payload class or type
     * @param mixed $payload Actual payload
     * @param ?Throwable $previous Hydration failure that rejected the payload
     */
    public function __construct(string $command, string $expectedType, mixed $payload, ?Throwable $previous = null)
    {
        $message = "Invalid payload type for {$command}: expected {$expectedType}, got " . get_debug_type($payload);
        if ($previous !== null) {
            $message .= '; fromArray failed: ' . get_class($previous) . ': ' . $previous->getMessage();
        }

        parent::__construct($message, 0, $previous);
    }
}
