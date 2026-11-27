<?php

declare(strict_types=1);

namespace Hilos\Fs\Exception;

use Hilos\Fs\FsException;

/**
 * Thrown when bytes handed to the MaxMind DB reader are not a MaxMind DB file or are damaged.
 */
class MmdbFormatException extends FsException
{
}
