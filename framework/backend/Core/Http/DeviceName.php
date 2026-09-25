<?php

declare(strict_types=1);

namespace Hilos\Core\Http;

/**
 * Names a browser device from its User-Agent for sessions, push subscriptions and passkeys.
 *
 * The name is a label, never an identity: the header is client-controlled, and an unknown
 * value stays null. Browser and platform vocabularies are deliberately centralized here so
 * later improvements apply to every device surface together.
 *
 * The two tables are ordered because their substring vocabularies overlap. More specific
 * tokens must precede the browser or platform they imitate.
 */
final class DeviceName
{
    /** @var array<string, string> User-Agent token => browser name, most specific first. */
    private const array BROWSERS = [
        'Edg' => 'Edge',
        'OPR' => 'Opera',
        'Opera' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'FxiOS' => 'Firefox',
        'Firefox' => 'Firefox',
        'CriOS' => 'Chrome',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ];

    /** @var array<string, string> User-Agent token => platform name, most specific first. */
    private const array PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'CrOS' => 'ChromeOS',
        'Windows' => 'Windows',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    /**
     * @param ?string $userAgent Client User-Agent, or null when none was sent
     * @return ?string Name such as `Chrome on macOS`, or null when either half is unknown
     */
    public static function fromUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $browser = self::firstMatch($userAgent, self::BROWSERS);
        $platform = self::firstMatch($userAgent, self::PLATFORMS);
        if ($browser === null || $platform === null) {
            return null;
        }

        return $browser . ' on ' . $platform;
    }

    /**
     * @param string $userAgent Client User-Agent
     * @param array<string, string> $names Ordered token-to-name table
     * @return ?string First matching name, or null when no token is present
     */
    private static function firstMatch(string $userAgent, array $names): ?string
    {
        foreach ($names as $token => $name) {
            if (str_contains($userAgent, $token)) {
                return $name;
            }
        }

        return null;
    }
}
