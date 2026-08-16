<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

/**
 * Names a passkey after the device it was enrolled on, from the User-Agent (HIL-418).
 *
 * A person recognizes their laptop, not a registry row: the profile lists keys as
 * "Chrome on macOS" rather than by credential id. The name is taken once, at
 * registration, from the User-Agent the client sends alongside the attestation —
 * the same source push subscriptions already name devices from.
 *
 * The name is a LABEL, never an identity: nothing is authorized by it, the
 * User-Agent is client-controlled and freely spoofable, and a wrong or missing
 * name costs a person nothing but a generic row. That is why an unrecognized
 * string resolves to null instead of to a guess — and why the cost of the whole
 * approach is worth naming: a synced passkey usable on every device the account
 * owns keeps the name of the one it was created on.
 *
 * The two tables below are ORDERED: every match is a substring test, and the
 * vocabularies overlap on purpose (Edge and Opera both say `Chrome`, Chrome says
 * `Safari`, an iPad says `Macintosh` in desktop mode). The first hit wins, so a
 * more specific token must precede the one it is disguised as.
 */
final class PasskeyDeviceName
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
     * Resolves the display name of the device a passkey is being enrolled on.
     *
     * Both halves must be recognized: a half-name ("Chrome on ") reads as a bug,
     * while null lets the surface fall back to a plain "Passkey" row.
     *
     * @param ?string $userAgent The client's User-Agent, or null when it sent none
     * @return ?string A name like `Chrome on macOS`, or null when the agent is unrecognized
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
     * Returns the name of the first token the User-Agent contains.
     *
     * @param string $userAgent The client's User-Agent
     * @param array<string, string> $names Ordered token => name table
     * @return ?string The matched name, or null when no token is present
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
