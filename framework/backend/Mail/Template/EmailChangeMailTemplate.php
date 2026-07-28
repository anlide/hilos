<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/**
 * Confirms a new email address for an existing user (HIL-197).
 */
final class EmailChangeMailTemplate extends AbstractVerificationCodeMailTemplate
{
    /**
     * @return string Subject line
     */
    protected function subject(): string
    {
        return 'Confirm your new email address';
    }

    /**
     * @param string $code Plaintext code to embed
     * @return string Plain-text body
     */
    protected function body(string $code): string
    {
        return "Use this code to confirm your new email address: {$code}\n\n"
            . 'If you did not request this change, you can ignore this message.';
    }
}
