<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource\Exception;

use Hilos\HilosException;

/**
 * Exception: create operation not allowed (no create permission registered for collection).
 */
class CreateNotAllowedException extends HilosException
{
}
