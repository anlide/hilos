<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/**
 * Confirms an email address being added to a signed-in user (HIL-197).
 */
final class EmailAddMailTemplate extends AbstractVerificationCodeMailTemplate
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
        return "Use this code to add this email address to your account: {$code}\n\n"
            . 'If you did not request this, you can ignore this message.';
    }
}
