<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\HilosException;

/**
 * Exception: cloning is not allowed (e.g. DbContext cannot be cloned).
 */
class CloneNotAllowedException extends HilosException
{
}
