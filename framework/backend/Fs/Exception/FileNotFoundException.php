<?php

declare(strict_types=1);

namespace Hilos\Fs\Exception;

use Hilos\Fs\FsException;

/**
 * Thrown when an expected file does not exist on disk.
 */
class FileNotFoundException extends FsException
{
}
