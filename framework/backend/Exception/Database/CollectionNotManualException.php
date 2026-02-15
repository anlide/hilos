<?php

declare(strict_types=1);

namespace Hilos\Exception\Database;

use Hilos\Exception\HilosException;

/**
 * Exception: operation requires manual collection (or item has no ID).
 */
class CollectionNotManualException extends HilosException
{
}
