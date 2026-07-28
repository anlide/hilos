<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/**
 * Confirms a freshly registered email identity (HIL-197).
 */
final class RegisterConfirmMailTemplate extends AbstractVerificationCodeMailTemplate
{
    /**
     * @return string Subject line
     */
    protected function subject(): string
    {
        return 'Confirm your email address';
    }

    /**
     * @param string $code Plaintext code to embed
     * @return string Plain-text body
     */
    protected function body(string $code): string
    {
        return "Use this code to confirm your email address: {$code}\n\n"
            . 'If you did not create an account, you can ignore this message.';
    }
}
