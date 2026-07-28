<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/**
 * Delivers a code to reset a password on an existing password identity (HIL-197).
 */
final class PasswordResetMailTemplate extends AbstractVerificationCodeMailTemplate
{
    /**
     * @return string Subject line
     */
    protected function subject(): string
    {
        return 'Reset your password';
    }

    /**
     * @param string $code Plaintext code to embed
     * @return string Plain-text body
     */
    protected function body(string $code): string
    {
        return "Use this code to reset your password: {$code}\n\n"
            . 'If you did not request a password reset, you can ignore this message.';
    }
}
