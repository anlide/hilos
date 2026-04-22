<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\Core\Exception\ValidationException;

/**
 * Exception: table action validation failure (e.g. entity not found, invalid input).
 *
 * Caught in page onAction handlers and forwarded to the client as an error signal.
 */
class TableActionException extends ValidationException
{
}
