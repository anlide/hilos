<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when too many open files (EMFILE/ENFILE)
 */
class TooManyOpenFilesException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Too many open files";
        // EMFILE (24) = per-process limit, ENFILE (23) = system limit
        parent::__construct($message, 24, $previous); // EMFILE
    }
}

