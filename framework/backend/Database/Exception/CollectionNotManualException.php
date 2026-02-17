<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\HilosException;

/**
 * Exception: operation requires manual collection (or item has no ID).
 */
class CollectionNotManualException extends HilosException
{
}
