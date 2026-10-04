<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use JsonException;

/** Signs the session-bound first sign-in capability held by the tab (HIL-1235). */
final class OAuthAccountTokenSigner
{
    private const string DOMAIN_TAG = 'oauth-account';
    private const string PART_SEPARATOR = '.';
    private const string FIELD_TAG = 'tag';
    private const string FIELD_PROVIDER = 'provider';
    private const string FIELD_SUBJECT = 'subject';
    private const string FIELD_EMAIL = 'email';
    private const string FIELD_NAME = 'name';
    private const string FIELD_SESSION = 'session';
    private const string FIELD_EXPIRES = 'expires';

    /** @param string $secret HMAC signing secret */
    public function __construct(private readonly string $secret)
    {
    }

    /**
     * @param string $provider Provider key
     * @param string $subject Provider-immutable account id
     * @param ?string $email Provider-reported address
     * @param string $displayName Provider-reported display name
     * @param string $sessionToken Current browser session token
     * @param int $ttlSeconds Lifetime in seconds
     * @return string Signed account capability
     * @throws JsonException When provider facts cannot be encoded
     */
    public function issue(
        string $provider,
        string $subject,
        ?string $email,
        string $displayName,
        string $sessionToken,
        int $ttlSeconds,
    ): string {
        $payload = json_encode([
            self::FIELD_TAG => self::DOMAIN_TAG,
            self::FIELD_PROVIDER => $provider,
            self::FIELD_SUBJECT => $subject,
            self::FIELD_EMAIL => $email,
            self::FIELD_NAME => $displayName,
            self::FIELD_SESSION => ProtectedModeRuntime::hashSessionToken($sessionToken),
            self::FIELD_EXPIRES => time() + $ttlSeconds,
        ], JSON_THROW_ON_ERROR);
        $encodedPayload = self::base64UrlEncode($payload);

        return $encodedPayload . self::PART_SEPARATOR . $this->sign($encodedPayload);
    }

    /**
     * @param string $token Signed token to read
     * @param string $sessionToken Current browser session token
     * @return ?OAuthAccountTokenData Provider facts, or null for any invalid token
     */
    public function verify(string $token, string $sessionToken): ?OAuthAccountTokenData
    {
        $parts = explode(self::PART_SEPARATOR, $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;
        if (!hash_equals($this->sign($encodedPayload), $signature)) {
            return null;
        }

        $payload = base64_decode(strtr($encodedPayload, '-_', '+/'), true);
        if ($payload === false) {
            return null;
        }

        $fields = json_decode($payload, true);
        if (!is_array($fields)
            || ($fields[self::FIELD_TAG] ?? null) !== self::DOMAIN_TAG
            || !is_string($fields[self::FIELD_PROVIDER] ?? null) || $fields[self::FIELD_PROVIDER] === ''
            || !is_string($fields[self::FIELD_SUBJECT] ?? null) || $fields[self::FIELD_SUBJECT] === ''
            || !array_key_exists(self::FIELD_EMAIL, $fields)
            || ($fields[self::FIELD_EMAIL] !== null && !is_string($fields[self::FIELD_EMAIL]))
            || !is_string($fields[self::FIELD_NAME] ?? null) || $fields[self::FIELD_NAME] === ''
            || !is_string($fields[self::FIELD_SESSION] ?? null)
            || !hash_equals(ProtectedModeRuntime::hashSessionToken($sessionToken), $fields[self::FIELD_SESSION])
            || !is_int($fields[self::FIELD_EXPIRES] ?? null)
            || time() > $fields[self::FIELD_EXPIRES]
        ) {
            return null;
        }

        return new OAuthAccountTokenData(
            $fields[self::FIELD_PROVIDER],
            $fields[self::FIELD_SUBJECT],
            $fields[self::FIELD_EMAIL],
            $fields[self::FIELD_NAME],
        );
    }

    /**
     * @param string $encodedPayload Encoded payload
     * @return string Encoded signature
     */
    private function sign(string $encodedPayload): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret, true));
    }

    /**
     * @param string $value Raw bytes
     * @return string URL-safe base64
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
