<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\Template\AbstractVerificationCodeMailTemplate;
use Hilos\Mail\Template\MagicLinkMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;

/**
 * MailVerificationDeliverer - the framework-default deliverer that emails the code (HIL-197).
 *
 * Replaces the dev-stub {@see LogVerificationDeliverer} as the {@see VerificationService}
 * default: it maps the email-delivered {@see VerificationType} to its mail template key
 * (the same `'auth.' . $type` rule the catalog is keyed by) and hands the code to the mail
 * subsystem through {@see Hilos::$mail} as a raw-send. The template is resolved agent-side,
 * so the plaintext code travels only in the queued signal params, never through a log — the
 * design ban on logging auth secrets in production is why the log stub stops being default.
 *
 * Only the email verification types are delivered here; a type with no email template (the
 * SMS types) is a silent no-op, since email is not its channel. Magic-link sign-in forwards
 * the raw token as the link param — assembling the full clickable URL (which needs the app's
 * base URL) is the sign-in flow's job, not this framework deliverer's.
 */
final class MailVerificationDeliverer implements VerificationDeliverer
{
    /**
     * Emails a plaintext verification code, resolving the template from the type.
     *
     * @param string $identifier Normalized target email the code was issued for
     * @param string $type Verification type (see VerificationType)
     * @param string $code Plaintext code (or magic-link token) to deliver
     * @throws EnvException When the mail worker count is unreadable while sharding the address
     * @throws ValidationException When the code was issued for a blank address
     */
    public function deliver(string $identifier, string $type, string $code): void
    {
        $templateKey = $this->templateKeyFor($type);
        if ($templateKey === null) {
            return;
        }

        Hilos::$mail?->send(new MailSendSignalData(
            to: $identifier,
            shardKey: HilosMailer::shardKeyForAddress($identifier),
            templateKey: $templateKey,
            params: $this->paramsFor($type, $code),
        ));
    }

    /**
     * Maps an email verification type to its mail template key, or null for a non-email type.
     *
     * @param string $type Verification type (see VerificationType)
     * @return ?string Template key for an email type, null for the SMS (non-email) types
     */
    private function templateKeyFor(string $type): ?string
    {
        return match ($type) {
            VerificationType::REGISTER_CONFIRM => MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM,
            VerificationType::PASSWORD_RESET => MailTemplateCatalogConstants::AUTH_PASSWORD_RESET,
            VerificationType::EMAIL_CHANGE => MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE,
            VerificationType::MAGIC_LINK => MailTemplateCatalogConstants::AUTH_MAGIC_LINK,
            VerificationType::EMAIL_ADD => MailTemplateCatalogConstants::AUTH_EMAIL_ADD,
            default => null,
        };
    }

    /**
     * Builds the template render params carrying the code under the type's param key.
     *
     * The magic-link template embeds a link, the code-carrying templates a code, so the
     * secret is placed under the matching param key.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $code Plaintext code (or magic-link token)
     * @return array<string, string> Single-entry render params for the resolved template
     */
    private function paramsFor(string $type, string $code): array
    {
        return $type === VerificationType::MAGIC_LINK
            ? [MagicLinkMailTemplate::PARAM_LINK => $code]
            : [AbstractVerificationCodeMailTemplate::PARAM_CODE => $code];
    }
}
