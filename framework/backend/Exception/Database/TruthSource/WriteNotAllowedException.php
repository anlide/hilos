<?php

declare(strict_types=1);

namespace Hilos\Exception\Database\TruthSource;

use Hilos\Exception\HilosException;

/**
 * Exception: write operation not allowed (no truth source registered for Db layer).
 */
class WriteNotAllowedException extends HilosException
{
}
