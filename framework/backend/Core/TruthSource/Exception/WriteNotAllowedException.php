<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource\Exception;

use Hilos\HilosException;

/**
 * Exception: write operation not allowed (no truth source registered for Db layer).
 */
class WriteNotAllowedException extends HilosException
{
}
