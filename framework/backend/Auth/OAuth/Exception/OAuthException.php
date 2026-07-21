<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Base exception for the OAuth login subsystem (HIL-281).
 *
 * The subsystem base under {@see HilosException} so a caller can document a
 * single throws for the OAuth surface; concrete children distinguish the
 * failure kind (state/CSRF, provider response, unknown provider).
 */
class OAuthException extends HilosException
{
    /**
     * Creates an OAuth exception.
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
