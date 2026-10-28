<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/** Confirmation content only; the mail channel applies the configured outer frame once. */
final class StatusSubscriptionConfirmMailTemplate implements MailTemplate
{
    public const string PARAM_PROJECT_NAME = 'projectName';
    public const string PARAM_STATUS_URL = 'statusUrl';
    public const string PARAM_CONFIRM_URL = 'confirmUrl';
    public const string PARAM_UNSUBSCRIBE_URL = 'unsubscribeUrl';
    public const string PARAM_EXPIRES_AT_LABEL = 'expiresAtLabel';

    /**
     * @param array<string, mixed> $params Project, public links and recipient-zone expiry label
     * @param ?string $locale Ordering caller's locale, ignored today pending template translations
     * @return EmailContent Plain and HTML alternatives in the current catalog language
     * @throws MailTemplateParamMissingException When a required text parameter is absent or blank
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $projectName = self::text($params, self::PARAM_PROJECT_NAME);
        $statusUrl = self::text($params, self::PARAM_STATUS_URL);
        $confirmUrl = self::text($params, self::PARAM_CONFIRM_URL);
        $unsubscribeUrl = self::text($params, self::PARAM_UNSUBSCRIBE_URL);
        $expiresAtLabel = self::text($params, self::PARAM_EXPIRES_AT_LABEL);
        $text = "Someone asked to send status updates of {$projectName} to this address. If it was you, confirm it.\n\n"
            . "Confirm your subscription: {$confirmUrl}\n"
            . "This link expires at {$expiresAtLabel}.\n\n"
            . "Status page: {$statusUrl}\n"
            . "Unsubscribe: {$unsubscribeUrl}\n\n"
            . 'Not you? Ignore this letter — nothing will be sent.';

        $projectName = self::escape($projectName);
        $statusUrl = self::escape($statusUrl);
        $confirmUrl = self::escape($confirmUrl);
        $unsubscribeUrl = self::escape($unsubscribeUrl);
        $expiresAtLabel = self::escape($expiresAtLabel);

        return new EmailContent(
            subject: 'Confirm your subscription to status updates',
            text: $text,
            html: "<p>Someone asked to send status updates of {$projectName} to this address. If it was you, confirm it.</p>"
                . "<p><a href=\"{$confirmUrl}\">Confirm your subscription</a></p>"
                . "<p>This link expires at {$expiresAtLabel}.</p>"
                . "<p><a href=\"{$statusUrl}\">Status page</a> · <a href=\"{$unsubscribeUrl}\">Unsubscribe</a></p>"
                . '<p>Not you? Ignore this letter — nothing will be sent.</p>',
        );
    }

    /**
     * @param array<string, mixed> $params Caller-supplied template parameters
     * @param string $key Required text parameter
     * @return string Nonempty supplied text
     * @throws MailTemplateParamMissingException When the parameter is absent, mistyped or blank
     */
    private static function text(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new MailTemplateParamMissingException('Status confirmation mail needs a non-empty ' . $key . ' param');
        }
        return $value;
    }

    /**
     * @param string $text Caller-supplied text or attribute value
     * @return string Safe HTML text and quoted attribute content
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
