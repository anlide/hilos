<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * NotificationVerificationDeliverer - the routing deliverer over the code channels (HIL-285).
 *
 * The framework default behind {@see VerificationService::createDeliverer()}: it dispatches a
 * freshly issued code to the channel its type belongs to - the SMS types (sms_login, sms_add)
 * to {@see SmsVerificationDeliverer} ({@see Hilos::$sms}), every other type to
 * {@see MailVerificationDeliverer} ({@see Hilos::$mail}). This generalizes the former
 * mail-only default now that a second raw-send channel exists (HIL-197 email, HIL-285 SMS),
 * so a project that adds a phone identity gets its login/add codes texted with no wiring. The
 * per-channel deliverers are injected so a project can swap either.
 */
final class NotificationVerificationDeliverer implements VerificationDeliverer
{
    /**
     * @param VerificationDeliverer $mail Deliverer for the email code types
     * @param VerificationDeliverer $sms Deliverer for the SMS code types
     */
    public function __construct(
        private readonly VerificationDeliverer $mail = new MailVerificationDeliverer(),
        private readonly VerificationDeliverer $sms = new SmsVerificationDeliverer(),
    ) {
    }

    /**
     * Routes a code to the deliverer for its verification type.
     *
     * @param string $identifier Normalized target the code was issued for
     * @param string $type Verification type (see VerificationType)
     * @param string $code Plaintext code (or assembled magic-link URL) to deliver
     * @throws EnvException When the target deliverer cannot shard the address
     */
    public function deliver(string $identifier, string $type, string $code): void
    {
        $this->delivererFor($type)->deliver($identifier, $type, $code);
    }

    /**
     * Selects the channel deliverer for a verification type.
     *
     * @param string $type Verification type (see VerificationType)
     * @return VerificationDeliverer SMS deliverer for the SMS types, else the mail deliverer
     */
    private function delivererFor(string $type): VerificationDeliverer
    {
        return match ($type) {
            VerificationType::SMS_LOGIN, VerificationType::SMS_ADD => $this->sms,
            default => $this->mail,
        };
    }
}
