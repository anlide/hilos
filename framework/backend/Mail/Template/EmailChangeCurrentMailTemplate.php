<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/**
 * Asks the holder of the address on file to confirm an email change (HIL-299).
 *
 * The first letter of the profile change-email flow goes to the address the account holds
 * now, before anything about the new one is asked. It warns rather than welcomes: whoever
 * reads it may be the owner, or somebody who found a signed-in session left open.
 */
final class EmailChangeCurrentMailTemplate extends AbstractVerificationCodeMailTemplate
{
    /**
     * @return string Subject line
     */
    protected function subject(): string
    {
        return 'Confirm it is you to change your email address';
    }

    /**
     * @param string $code Plaintext code to embed
     * @return string Plain-text body
     */
    protected function body(string $code): string
    {
        return "Somebody asked to change the email address of your account. Use this code to confirm it is you: {$code}\n\n"
            . 'If this was not you, do not share this code with anyone: somebody may be signed in to your account.';
    }
}
