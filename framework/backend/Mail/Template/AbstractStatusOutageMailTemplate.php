<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/** Public event content only; the existing mail channel owns the outer frame. */
abstract class AbstractStatusOutageMailTemplate implements MailTemplate
{
    public const string PARAM_PROJECT_NAME = 'projectName';
    public const string PARAM_STATUS_URL = 'statusUrl';
    public const string PARAM_UNSUBSCRIBE_URL = 'unsubscribeUrl';
    public const string PARAM_COMPONENT_NAME = 'componentName';
    public const string PARAM_SEVERITY = 'severity';
    public const string PARAM_TEXT = 'text';
    public const string PARAM_DETECTED_AT_LABEL = 'detectedAtLabel';
    public const string PARAM_EVENT_AT_LABEL = 'eventAtLabel';
    public const string PARAM_RESOLVED_AT_LABEL = 'resolvedAtLabel';

    protected const string SUBJECT = '%s: %s is unavailable';
    protected const bool RESOLVED = false;

    /**
     * @param array<string, mixed> $params Public event, links and recipient-zone labels; resolvedAtLabel is explicitly nullable
     * @param ?string $locale Caller's target locale, currently the project default
     * @return EmailContent Escaped HTML and plain alternatives without an outer frame
     * @throws MailTemplateParamMissingException When required text or the applicable resolution label is missing
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $values = [];
        foreach ([self::PARAM_PROJECT_NAME, self::PARAM_STATUS_URL, self::PARAM_UNSUBSCRIBE_URL,
            self::PARAM_COMPONENT_NAME, self::PARAM_SEVERITY, self::PARAM_TEXT,
            self::PARAM_DETECTED_AT_LABEL, self::PARAM_EVENT_AT_LABEL] as $key) {
            $values[$key] = self::text($params, $key);
        }
        if (!array_key_exists(self::PARAM_RESOLVED_AT_LABEL, $params)
            || (!static::RESOLVED && $params[self::PARAM_RESOLVED_AT_LABEL] !== null)) {
            throw new MailTemplateParamMissingException('Status outage mail needs an applicable resolvedAtLabel param');
        }
        $resolved = static::RESOLVED ? self::text($params, self::PARAM_RESOLVED_AT_LABEL) : null;
        $subject = sprintf(static::SUBJECT, ucfirst(str_replace('_', ' ', $values[self::PARAM_SEVERITY])),
            $values[self::PARAM_COMPONENT_NAME]);
        $text = $values[self::PARAM_PROJECT_NAME] . "\n\n" . $values[self::PARAM_COMPONENT_NAME] . "\n"
            . $values[self::PARAM_SEVERITY] . "\n" . $values[self::PARAM_TEXT] . "\n\n"
            . 'Detected at: ' . $values[self::PARAM_DETECTED_AT_LABEL] . "\n"
            . 'Updated at: ' . $values[self::PARAM_EVENT_AT_LABEL] . "\n"
            . ($resolved === null ? '' : 'Resolved at: ' . $resolved . "\n")
            . "\nStatus page: " . $values[self::PARAM_STATUS_URL] . "\nUnsubscribe: " . $values[self::PARAM_UNSUBSCRIBE_URL];
        foreach ($values as $key => $value) {
            $values[$key] = self::escape($value);
        }
        $html = '<p>' . $values[self::PARAM_PROJECT_NAME] . '</p><p>' . $values[self::PARAM_COMPONENT_NAME]
            . '<br>' . $values[self::PARAM_SEVERITY] . '</p><p>' . nl2br($values[self::PARAM_TEXT]) . '</p>'
            . '<p>Detected at: ' . $values[self::PARAM_DETECTED_AT_LABEL]
            . '<br>Updated at: ' . $values[self::PARAM_EVENT_AT_LABEL]
            . ($resolved === null ? '' : '<br>Resolved at: ' . self::escape($resolved)) . '</p>'
            . '<p><a href="' . $values[self::PARAM_STATUS_URL] . '">Status page</a> · <a href="'
            . $values[self::PARAM_UNSUBSCRIBE_URL] . '">Unsubscribe</a></p>';
        return new EmailContent(subject: $subject, text: $text, html: $html);
    }

    /**
     * @param array<string, mixed> $params Template boundary values
     * @param string $key Required text key
     * @return string Supplied nonblank text
     * @throws MailTemplateParamMissingException When the value is missing, mistyped or blank
     */
    private static function text(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new MailTemplateParamMissingException('Status outage mail needs a non-empty ' . $key . ' param');
        }
        return $value;
    }

    /**
     * @param string $text Supplied text or quoted attribute
     * @return string Safe HTML content
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
