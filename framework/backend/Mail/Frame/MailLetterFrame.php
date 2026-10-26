<?php

declare(strict_types=1);

namespace Hilos\Mail\Frame;

use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\Mail\Exception\MailLetterFrameInvalidException;

/** Pure validation and single-pass composition of an HTML letter. */
final class MailLetterFrame
{
    public const string CONTENT = '[[:content]]';
    public const string LANG = '[[:lang]]';
    public const string DIR = '[[:dir]]';

    public const string CONTENT_REFUSAL = 'The frame must contain [[:content]] exactly once.';
    public const string PLACEHOLDER_REFUSAL = 'The frame contains an unknown placeholder.';
    public const string TYPE_REFUSAL = 'Frame HTML must be text.';

    public const string DEFAULT_HTML = <<<'HTML'
<!doctype html>
<html lang="[[:lang]]" dir="[[:dir]]">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background-color:#f3f4f6;color:#1f2937;font-family:Arial,sans-serif;">
<table role="presentation" style="width:100%;border-collapse:collapse;"><tr><td style="padding:24px;">
<table role="presentation" style="width:100%;max-width:600px;margin:0 auto;border-collapse:collapse;background-color:#ffffff;">
<tr><td style="padding:24px;">[[:content]]</td></tr>
</table>
</td></tr></table>
</body>
</html>
HTML;

    /**
     * @param string $frame HTML with exactly one content slot
     * @return ?string Safe refusal sentence, or null when valid
     */
    public static function validate(string $frame): ?string
    {
        if (substr_count($frame, self::CONTENT) !== 1) {
            return self::CONTENT_REFUSAL;
        }

        preg_match_all('/\[\[:.*?\]\]/s', $frame, $placeholders);
        foreach ($placeholders[0] as $placeholder) {
            if (!in_array($placeholder, [self::CONTENT, self::LANG, self::DIR], true)) {
                return self::PLACEHOLDER_REFUSAL;
            }
        }

        return null;
    }

    /**
     * Inserts content verbatim; placeholders inside it are never expanded.
     *
     * @param string $frame HTML frame to validate and fill
     * @param string $html Already rendered HTML body
     * @param string $language Language tag of that body
     * @return string Complete framed HTML
     * @throws MailLetterFrameInvalidException When the frame has missing, repeated or unknown slots
     */
    public static function render(string $frame, string $html, string $language): string
    {
        if (self::validate($frame) !== null) {
            throw new MailLetterFrameInvalidException();
        }

        $languageCode = strtolower(explode('-', str_replace('_', '-', $language), 2)[0]);

        return strtr($frame, [
            self::CONTENT => $html,
            self::LANG => htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            self::DIR => BuiltInI18nCatalog::language($languageCode)?->rtl === true ? 'rtl' : 'ltr',
        ]);
    }
}
