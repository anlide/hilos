<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Base exception for the WebAuthn / passkey subsystem (HIL-284).
 *
 * The subsystem base under {@see HilosException} so a caller can document a
 * single throws for the passkey crypto surface; concrete children distinguish
 * the failure kind (challenge token vs ceremony verification).
 */
class WebAuthnException extends HilosException
{
    /**
     * Creates a WebAuthn exception.
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
