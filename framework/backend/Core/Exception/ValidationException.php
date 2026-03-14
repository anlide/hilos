<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

use Hilos\HilosException;

/**
 * Base exception for validation errors (empty value, length, format, etc.).
 */
class ValidationException extends HilosException
{
}
