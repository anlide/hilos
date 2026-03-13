<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

use Hilos\HilosException;

/**
 * Base exception for process-related errors (spawn, pipe, terminate).
 */
class ProcessException extends HilosException
{
}
