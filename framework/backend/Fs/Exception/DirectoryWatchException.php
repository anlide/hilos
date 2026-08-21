<?php

declare(strict_types=1);

namespace Hilos\Fs\Exception;

use Hilos\Fs\FsException;

/**
 * Thrown when a directory cannot be taken under filesystem watch.
 */
class DirectoryWatchException extends FsException
{
}
