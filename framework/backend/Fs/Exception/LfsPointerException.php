<?php

declare(strict_types=1);

namespace Hilos\Fs\Exception;

use Hilos\Fs\FsException;

/**
 * The file is a Git LFS pointer: git-lfs is missing or the package archive omitted its LFS objects.
 */
class LfsPointerException extends FsException
{
}
