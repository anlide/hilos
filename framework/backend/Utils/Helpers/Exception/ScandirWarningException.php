<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Raised when {@see scandir()} triggers E_WARNING under a temporary error handler (see FileSystemHelper).
 */
final class ScandirWarningException extends HilosException
{
    /**
     * @param string $message PHP error message
     * @param int $severity E_WARNING or E_USER_WARNING
     * @param string $errorFile File from the PHP error context
     * @param int $errorLine Line from the PHP error context
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        string $message,
        public readonly int $severity,
        public readonly string $errorFile,
        public readonly int $errorLine,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
