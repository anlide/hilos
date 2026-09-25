<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

/**
 * Asks the holder of an account address to confirm a protected operation (HIL-495).
 */
final class StepUpMailTemplate extends AbstractVerificationCodeMailTemplate
{
    /**
     * @return string Subject line
     */
    protected function subject(): string
    {
        return 'Confirm it is you';
    }

    /**
     * @param string $code Plaintext code to embed
     * @return string Plain-text body
     */
    protected function body(string $code): string
    {
        return "Somebody asked to confirm a protected action on your account. Use this code to confirm it is you: {$code}\n\n"
            . 'If this was not you, do not share this code with anyone: somebody may be signed in to your account.';
    }
}
