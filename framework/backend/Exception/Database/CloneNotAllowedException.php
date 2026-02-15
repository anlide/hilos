<?php

declare(strict_types=1);

namespace Hilos\Exception\Database;

use Hilos\Exception\HilosException;

/**
 * Exception: cloning is not allowed (e.g. DbContext cannot be cloned).
 */
class CloneNotAllowedException extends HilosException
{
}
