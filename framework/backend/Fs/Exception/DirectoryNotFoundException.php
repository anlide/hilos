<?php

declare(strict_types=1);

namespace Hilos\Fs\Exception;

use Hilos\Fs\FsException;

/**
 * Thrown when a named FS directory is not registered in the context, or a
 * path primitive is handed a path that is not a directory.
 */
class DirectoryNotFoundException extends FsException
{
}
