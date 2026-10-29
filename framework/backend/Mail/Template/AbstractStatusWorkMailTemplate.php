<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/** Public maintenance words only; the mail channel applies the existing outer frame. */
abstract class AbstractStatusWorkMailTemplate implements MailTemplate
{
    public const string PARAM_PROJECT_NAME = 'projectName';
    public const string PARAM_STATUS_URL = 'statusUrl';
    public const string PARAM_UNSUBSCRIBE_URL = 'unsubscribeUrl';
    public const string PARAM_TITLE = 'title';
    public const string PARAM_DESCRIPTION = 'description';
    public const string PARAM_COMPONENT_NAMES = 'componentNames';
    public const string PARAM_STARTS_AT_LABEL = 'startsAtLabel';
    public const string PARAM_ENDS_AT_LABEL = 'endsAtLabel';
    public const string PARAM_CHANGES_TEXT = 'changesText';
    public const string PARAM_ENDED_AT_LABEL = 'endedAtLabel';
    public const string PARAM_COMPLETION_OBSERVED_AT_LABEL = 'completionObservedAtLabel';

    protected const string SUBJECT = 'Scheduled maintenance: %s';
    protected const bool CHANGED = false;
    protected const bool COMPLETED = false;

    /**
     * @param array<string, mixed> $params Public snapshot, links and recipient-zone labels with explicit nullable fields
     * @param ?string $locale Project locale selected by the existing channel
     * @return EmailContent Plain and escaped HTML alternatives without an outer frame
     * @throws MailTemplateParamMissingException When a required or event-dependent parameter is absent or mistyped
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $values = [];
        foreach ([self::PARAM_PROJECT_NAME, self::PARAM_STATUS_URL, self::PARAM_UNSUBSCRIBE_URL,
            self::PARAM_TITLE, self::PARAM_COMPONENT_NAMES, self::PARAM_STARTS_AT_LABEL, self::PARAM_ENDS_AT_LABEL] as $key) {
            $values[$key] = self::text($params, $key);
        }
        foreach ([self::PARAM_DESCRIPTION, self::PARAM_CHANGES_TEXT, self::PARAM_ENDED_AT_LABEL,
            self::PARAM_COMPLETION_OBSERVED_AT_LABEL] as $key) {
            if (!array_key_exists($key, $params)) {
                throw new MailTemplateParamMissingException('Status work mail needs an explicit ' . $key . ' param');
            }
            $values[$key] = $params[$key] === null ? null : self::text($params, $key);
        }
        if ((static::CHANGED !== ($values[self::PARAM_CHANGES_TEXT] !== null))
            || (static::COMPLETED !== ($values[self::PARAM_COMPLETION_OBSERVED_AT_LABEL] !== null))
            || (!static::COMPLETED && $values[self::PARAM_ENDED_AT_LABEL] !== null)) {
            throw new MailTemplateParamMissingException('Status work mail needs labels applicable to its event.');
        }
        $subject = sprintf(static::SUBJECT, $values[self::PARAM_TITLE]);
        $body = $values[self::PARAM_TITLE] . "\n"
            . 'Components: ' . $values[self::PARAM_COMPONENT_NAMES] . "\n"
            . 'Scheduled start: ' . $values[self::PARAM_STARTS_AT_LABEL] . "\n"
            . 'Scheduled end: ' . $values[self::PARAM_ENDS_AT_LABEL]
            . ($values[self::PARAM_DESCRIPTION] === null ? '' : "\n\n" . $values[self::PARAM_DESCRIPTION])
            . ($values[self::PARAM_CHANGES_TEXT] === null ? '' : "\n\nWhat changed:\n" . $values[self::PARAM_CHANGES_TEXT]);
        if (static::COMPLETED) {
            $body .= $values[self::PARAM_ENDED_AT_LABEL] === null
                ? "\n\nCompletion observed at: " . $values[self::PARAM_COMPLETION_OBSERVED_AT_LABEL]
                : "\n\nMaintenance ended at: " . $values[self::PARAM_ENDED_AT_LABEL];
            $body .= "\nService is open again.";
        }
        $text = $values[self::PARAM_PROJECT_NAME] . "\n\n" . $subject . "\n\n" . $body
            . "\n\nStatus page: " . $values[self::PARAM_STATUS_URL] . "\nUnsubscribe: " . $values[self::PARAM_UNSUBSCRIBE_URL];
        $html = '<p>' . self::escape($values[self::PARAM_PROJECT_NAME]) . '</p><h1>' . self::escape($subject) . '</h1><p>'
            . nl2br(self::escape($body)) . '</p><p><a href="' . self::escape($values[self::PARAM_STATUS_URL])
            . '">Status page</a> · <a href="' . self::escape($values[self::PARAM_UNSUBSCRIBE_URL]) . '">Unsubscribe</a></p>';
        return new EmailContent(subject: $subject, text: $text, html: $html);
    }

    /**
     * @param array<string, mixed> $params Template boundary
     * @param string $key Nonblank string parameter
     * @return string Supplied public text
     * @throws MailTemplateParamMissingException When text is absent, blank or mistyped
     */
    private static function text(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new MailTemplateParamMissingException('Status work mail needs a non-empty ' . $key . ' param');
        }
        return $value;
    }

    /**
     * @param string $text Public text or HTML attribute
     * @return string Escaped HTML content
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
