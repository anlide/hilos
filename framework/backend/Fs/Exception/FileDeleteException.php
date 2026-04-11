<?php

declare(strict_types=1);

namespace Hilos\Fs\Exception;

use Hilos\Fs\FsException;

/**
 * Thrown when a file deletion fails (file exists but cannot be removed).
 */
class FileDeleteException extends FsException
{
}
