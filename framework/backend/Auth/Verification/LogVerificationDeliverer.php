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
 */
final class LogVerificationDeliverer implements VerificationDeliverer
{
    /**
     * Logs the plaintext code so a developer can complete the flow locally.
     *
     * @param string $identifier Normalized target the code was issued for
     * @param string $type Verification type (see VerificationType)
     * @param string $code Plaintext code to deliver
     */
    public function deliver(string $identifier, string $type, string $code): void
    {
        Logger::info(
            'Verification code issued (dev-stub delivery)',
            ['type' => $type, 'identifier' => $identifier, 'code' => $code],
        );
    }
}
