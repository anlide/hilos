<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Core\Exception\InvalidArgumentException;
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
 * SMS types) is a silent no-op, since email is not its channel. Magic-link sign-in arrives
 * already assembled: {@see VerificationService::issue()} builds the clickable URL from the
 * project's return address before it hands anything to a deliverer, so what lands in the
 * link param is the address the recipient clicks (HIL-417).
 *
 * That letter carries TWO params rather than one (HIL-606): the URL to click and the code
 * to type. Which shape is being delivered is read off the {@see VerificationDeliverable}
 * and not off the type, so the params can never describe a different letter than the one
 * the service minted.
 */
final class MailVerificationDeliverer implements VerificationDeliverer
{
    /**
     * Emails a freshly issued challenge, resolving the template from the type.
     *
     * @param string $identifier Normalized target email the challenge was issued for
     * @param string $type Verification type (see VerificationType)
     * @param VerificationDeliverable $deliverable Plaintext content of the letter
     * @throws EnvException When the mail worker count is unreadable while sharding the address
     * @throws ValidationException When the challenge was issued for a blank address
     * @throws InvalidArgumentException When the mail send signal cannot be named or queued
     */
    public function deliver(string $identifier, string $type, VerificationDeliverable $deliverable): void
    {
        $templateKey = $this->templateKeyFor($type);
        if ($templateKey === null) {
            return;
        }

        Hilos::$mail?->send(new MailSendSignalData(
            to: $identifier,
            shardKey: HilosMailer::shardKeyForAddress($identifier),
            templateKey: $templateKey,
            params: $this->paramsFor($deliverable),
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
     * Builds the template render params from the shape of what is being delivered.
     *
     * A deliverable carrying a link is a magic-link letter and needs both of that
     * template's params; anything else is one of the code-carrying templates and needs
     * the one. The question is asked of the deliverable rather than of the type because
     * the deliverable is what the mint actually produced - keyed on the type, a letter
     * could be described by params for a secret nobody issued.
     *
     * @param VerificationDeliverable $deliverable Plaintext content of the letter
     * @return array<string, string> Render params for the resolved template
     */
    private function paramsFor(VerificationDeliverable $deliverable): array
    {
        if ($deliverable->link === null) {
            return [AbstractVerificationCodeMailTemplate::PARAM_CODE => $deliverable->code];
        }

        return [
            MagicLinkMailTemplate::PARAM_LINK => $deliverable->link,
            MagicLinkMailTemplate::PARAM_CODE => $deliverable->code,
        ];
    }
}
