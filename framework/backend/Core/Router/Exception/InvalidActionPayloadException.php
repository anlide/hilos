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
}
