<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\HilosException;

/**
 * Base exception for page-related errors.
 *
 * Adds no behavior over {@see HilosException}; exists so callers can catch the
 * whole page subsystem with a single type.
 */
class PageException extends HilosException
{
}
