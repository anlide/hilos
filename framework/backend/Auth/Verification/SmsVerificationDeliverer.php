<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\HilosSmsSender;
use Hilos\Sms\Template\SmsTemplateCatalogConstants;
use Hilos\Sms\Template\SmsVerificationCodeTemplate;

/**
 * SmsVerificationDeliverer - the deliverer that texts a verification code (HIL-285).
 *
 * The SMS mirror of {@see MailVerificationDeliverer}: it maps the SMS-delivered
 * {@see VerificationType} values (sms_login, sms_add) to their `auth.*` SMS template key
 * (the same `'auth.' . $type` rule the catalog is keyed by) and hands the code to the SMS
 * subsystem through {@see Hilos::$sms} as a raw-send. The template is resolved agent-side, so
 * the plaintext code travels only in the queued signal params, never through a log.
 *
 * Only the SMS verification types are delivered here; a type with no SMS template (the email
 * types) is a silent no-op, since SMS is not its channel. Routed to alongside
 * {@see MailVerificationDeliverer} by {@see NotificationVerificationDeliverer}.
 */
final class SmsVerificationDeliverer implements VerificationDeliverer
{
    /**
     * Texts a plaintext verification code, resolving the template from the type.
     *
     * @param string $identifier Normalized target E.164 number the code was issued for
     * @param string $type Verification type (see VerificationType)
     * @param string $code Plaintext code to deliver
     * @throws EnvException When the SMS worker count is unreadable while sharding the number
     * @throws ValidationException When the code was issued for a blank number
     */
    public function deliver(string $identifier, string $type, string $code): void
    {
        $templateKey = $this->templateKeyFor($type);
        if ($templateKey === null) {
            return;
        }

        Hilos::$sms?->send(new SmsSendSignalData(
            to: $identifier,
            shardKey: HilosSmsSender::shardKeyForNumber($identifier),
            templateKey: $templateKey,
            params: [SmsVerificationCodeTemplate::PARAM_CODE => $code],
        ));
    }

    /**
     * Maps an SMS verification type to its template key, or null for a non-SMS type.
     *
     * @param string $type Verification type (see VerificationType)
     * @return ?string Template key for an SMS type, null for the email (non-SMS) types
     */
    private function templateKeyFor(string $type): ?string
    {
        return match ($type) {
            VerificationType::SMS_LOGIN => SmsTemplateCatalogConstants::AUTH_SMS_LOGIN,
            VerificationType::SMS_ADD => SmsTemplateCatalogConstants::AUTH_SMS_ADD,
            default => null,
        };
    }
}
