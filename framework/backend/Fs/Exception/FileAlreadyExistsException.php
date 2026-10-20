<?php

declare(strict_types=1);

namespace Hilos\Fs\Exception;

use Hilos\Fs\FsException;

/**
 * Thrown when exclusive publication finds an existing target entry.
 */
class FileAlreadyExistsException extends FsException
{
}
