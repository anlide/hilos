<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/**
 * Asks the holder of an account address to confirm a request to delete the account (HIL-302).
 */
final class AccountDeletionMailTemplate extends AbstractVerificationCodeMailTemplate
{
    /**
     * @return string Subject line
     */
    protected function subject(): string
    {
        return 'Confirm deleting your account';
    }

    /**
     * @param string $code Plaintext code to embed
     * @return string Plain-text body
     */
    protected function body(string $code): string
    {
        return "Somebody asked to delete your account. Use this code to confirm it: {$code}\n\n"
            . 'If this was not you, do not share this code with anyone: somebody may be signed in to your account.';
    }
}
