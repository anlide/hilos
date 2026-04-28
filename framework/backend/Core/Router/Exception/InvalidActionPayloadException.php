<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Exception;

use Hilos\Core\Table\Exception\TableActionException;

/**
 * Exception: action payload validation failure (e.g. missing required field, invalid format).
 *
 * Use when DTO or action handler detects invalid payload data.
 * Extends TableActionException so it is caught by page onAction handlers and forwarded to the client.
 */
class InvalidActionPayloadException extends TableActionException
{
    /**
     * Creates exception for an action payload that does not match the action contract.
     *
     * @param string $action Action name
     * @param string $expectedType Expected payload class or type
     * @param mixed $payload Actual payload
     */
    public function __construct(string $action, string $expectedType, mixed $payload)
    {
        parent::__construct(
            "Invalid payload for action {$action}: expected {$expectedType}, got " . get_debug_type($payload),
        );
    }
}
