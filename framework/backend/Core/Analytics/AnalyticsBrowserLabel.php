<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** Short browser, major version and operating-system labels from user-agent input. */
final class AnalyticsBrowserLabel
{
    private const int FALLBACK_MAX_CHARS = 60;

    private const string PART_SEPARATOR = ' · ';

    /** First matching pattern wins, including browsers whose agents also contain Chrome. */
    private const array BROWSER_PATTERNS = [
        '~Edg/([0-9]+)?~' => 'Edge',
        '~OPR/([0-9]+)?~' => 'Opera',
        '~SamsungBrowser/([0-9]+)?~' => 'Samsung Internet',
        '~(?:Firefox|FxiOS)/([0-9]+)?~' => 'Firefox',
        '~(?:CriOS|Chrome)/([0-9]+)?~' => 'Chrome',
        '~\A(?=.*Safari/).*?Version/([0-9]+)?~s' => 'Safari',
    ];

    /** First matching token wins, including mobile systems whose agents also name desktop systems. */
    private const array SYSTEM_TOKENS = [
        'Windows NT' => 'Windows',
        'iPhone' => 'iOS',
        'iPad' => 'iOS',
        'Android' => 'Android',
        'CrOS' => 'ChromeOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    /**
     * @param string $userAgent Raw current user-agent string
     * @return string Recognized parts, or a trimmed and bounded unrecognized string
     */
    public static function fromUserAgent(string $userAgent): string
    {
        $parts = [];
        foreach (self::BROWSER_PATTERNS as $pattern => $browser) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                $version = $matches[1] ?? null;
                $parts[] = $version === null ? $browser : $browser . ' ' . $version;
                break;
            }
        }
        foreach (self::SYSTEM_TOKENS as $token => $system) {
            if (str_contains($userAgent, $token)) {
                $parts[] = $system;
                break;
            }
        }

        return $parts === [] ? mb_substr(trim($userAgent), 0, self::FALLBACK_MAX_CHARS) : implode(self::PART_SEPARATOR, $parts);
    }
}
