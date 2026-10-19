<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

use Hilos\HilosException;

/**
 * Base exception for validation errors whose messages are written for the person who asked.
 */
class ValidationException extends HilosException implements PersonFacingFailure
{
}
