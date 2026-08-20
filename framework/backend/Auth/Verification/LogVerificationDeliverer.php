<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Utils\Logger;

/**
 * LogVerificationDeliverer - dev-stub deliverer that logs the code instead of sending it.
 *
 * Framework default binding for {@see VerificationDeliverer} until a real
 * delivery channel lands (HIL-197). Hilos has no mail/SMS transport today, so
 * the reference stack "delivers" a code by writing it to the log where a
 * developer can read it. Never wire this in production — it exposes codes to
 * anyone with log access.
 *
 * A magic-link issue logs BOTH halves of its letter (HIL-606): the developer who
 * reads this line is standing on the sign-in screen and may finish with either.
 */
final class LogVerificationDeliverer implements VerificationDeliverer
{
    /**
     * Logs the plaintext challenge so a developer can complete the flow locally.
     *
     * @param string $identifier Normalized target the challenge was issued for
     * @param string $type Verification type (see VerificationType)
     * @param VerificationDeliverable $deliverable Plaintext content of the letter or message
     */
    public function deliver(string $identifier, string $type, VerificationDeliverable $deliverable): void
    {
        Logger::info(
            'Verification code issued (dev-stub delivery)',
            [
                'type' => $type,
                'identifier' => $identifier,
                'code' => $deliverable->code,
                'link' => $deliverable->link,
            ],
        );
    }
}
