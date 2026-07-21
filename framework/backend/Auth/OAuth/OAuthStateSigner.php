<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Auth\OAuth\Exception\OAuthStateException;
use Random\RandomException;

/**
 * Issues and verifies the stateless, signed OAuth `state` token (HIL-281).
 *
 * The CSRF guard of the flow, kept storage-free by design: the `state` carried
 * through the provider is a signed token `base64url(payload).base64url(HMAC)`
 * where the payload binds the initiating session token, a random nonce, and an
 * absolute expiry. Verification recomputes the HMAC in constant time and checks
 * the session match and expiry — nothing is persisted in RT or DB, so there is
 * no ledger to store or reap. Single-use is best-effort via the short TTL (no
 * server-side replay ledger at this leaf, per the settled contract).
 *
 * The signing secret is injected (the agent reads it from env) so the signer
 * stays a pure, unit-testable primitive.
 */
final class OAuthStateSigner
{
    private const int NONCE_BYTES = 16;
    private const string FIELD_SEPARATOR = '|';
    private const string PART_SEPARATOR = '.';

    /**
     * @param string $secret HMAC signing secret (env-sourced by the caller)
     */
    public function __construct(
        private readonly string $secret,
    ) {
    }

    /**
     * Mints a signed state token bound to a session, valid for `ttlSeconds`.
     *
     * @param string $sessionToken Initiating session token to bind
     * @param int $ttlSeconds Lifetime in seconds from now
     * @return string Signed state token
     * @throws RandomException When the platform CSPRNG cannot produce a nonce
     */
    public function issue(string $sessionToken, int $ttlSeconds): string
    {
        $nonce = bin2hex(random_bytes(self::NONCE_BYTES));
        $expiry = time() + $ttlSeconds;

        $payload = $sessionToken . self::FIELD_SEPARATOR . $nonce . self::FIELD_SEPARATOR . $expiry;
        $encodedPayload = $this->base64UrlEncode($payload);

        return $encodedPayload . self::PART_SEPARATOR . $this->sign($encodedPayload);
    }

    /**
     * Verifies a state token against the initiating session and the clock.
     *
     * @param string $state State token returned to the callback
     * @param string $sessionToken Session token the callback arrived on
     * @throws OAuthStateException When the token is malformed, has a bad
     *   signature, is bound to a different session, or has expired
     */
    public function verify(string $state, string $sessionToken): void
    {
        $parts = explode(self::PART_SEPARATOR, $state, 2);
        if (count($parts) !== 2) {
            throw new OAuthStateException('OAuth state is malformed');
        }

        [$encodedPayload, $signature] = $parts;
        if (!hash_equals($this->sign($encodedPayload), $signature)) {
            throw new OAuthStateException('OAuth state signature is invalid');
        }

        $payload = $this->base64UrlDecode($encodedPayload);
        if ($payload === null) {
            throw new OAuthStateException('OAuth state payload is malformed');
        }

        $fields = explode(self::FIELD_SEPARATOR, $payload);
        if (count($fields) !== 3) {
            throw new OAuthStateException('OAuth state payload is malformed');
        }

        [$boundSessionToken, , $expiry] = $fields;
        if (!hash_equals($boundSessionToken, $sessionToken)) {
            throw new OAuthStateException('OAuth state is bound to a different session');
        }

        if (time() > (int)$expiry) {
            throw new OAuthStateException('OAuth state has expired');
        }
    }

    /**
     * @param string $encodedPayload base64url-encoded payload to sign
     * @return string base64url-encoded HMAC-SHA256 signature
     */
    private function sign(string $encodedPayload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret, true));
    }

    /**
     * @param string $value Raw value to encode
     * @return string URL-safe, unpadded base64
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param string $value URL-safe base64 to decode
     * @return ?string Decoded value, or null when it is not valid base64
     */
    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
