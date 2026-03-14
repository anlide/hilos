<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when too many open files (EMFILE/ENFILE).
 */
class TooManyOpenFilesException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "Too many open files";
        // EMFILE (24) = per-process limit, ENFILE (23) = system limit
        parent::__construct($message, 24, $previous); // EMFILE
    }
}
