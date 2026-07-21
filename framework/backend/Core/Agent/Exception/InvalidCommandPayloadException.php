<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

/**
 * Exception when a routed CLI command has a payload that does not match the command contract.
 */
class InvalidCommandPayloadException extends AgentException
{
    /**
     * Creates exception for an invalid command payload.
     *
     * @param string $command Command name
     * @param string $expectedType Expected payload class or type
     * @param mixed $payload Actual payload
     */
    public function __construct(string $command, string $expectedType, mixed $payload)
    {
        parent::__construct(
            "Invalid payload type for {$command}: expected {$expectedType}, got " . get_debug_type($payload),
        );
    }
}
