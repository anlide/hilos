<?php

declare(strict_types=1);

namespace Hilos\Fs\Exception;

use Hilos\Fs\FsException;

/**
 * Thrown when a named FS directory is not registered in the context.
 */
class DirectoryNotFoundException extends FsException
{
}
