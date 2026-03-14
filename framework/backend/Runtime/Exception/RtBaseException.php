<?php

declare(strict_types=1);

namespace Hilos\Runtime\Exception;

use Hilos\HilosException;

/**
 * Base exception for Runtime (rt) system.
 *
 * "rt" denotes runtime data storage section. Do not confuse with PHP \RuntimeException.
 */
class RtBaseException extends HilosException
{
}
