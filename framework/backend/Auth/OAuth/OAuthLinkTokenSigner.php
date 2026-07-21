<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * Issues and verifies the stateless, signed OAuth account-link token (HIL-282).
 *
 * The capability the email-collision branch hands the browser so it can finish
 * linking a new OAuth account to an existing one after a full re-authentication.
 * Kept storage-free, exactly like {@see OAuthStateSigner}: the token is a signed
 * `base64url(payload).base64url(HMAC)` whose payload binds a fixed domain tag, the
 * provider, the account subject, the colliding email, and an absolute expiry.
 * Verification recomputes the HMAC in constant time and checks the domain tag,
 * field shape, and expiry — nothing is persisted, so there is no ledger to reap.
 *
 * The token carries no nonce and is not single-use: it is not a CSRF binder but a
 * short-lived pointer to "(provider, subject) may be linked to whoever owns this
 * email". The link action re-checks ownership against the re-authenticated user
 * and the bind is idempotent (a duplicate `(provider, subject)` is refused), so a
 * replay within the TTL grants nothing on its own.
 *
 * The {@see DOMAIN_TAG} first field gives cryptographic domain separation from
 * {@see OAuthStateSigner} when both share one app secret: a state token (three
 * fields, no tag) can never be reinterpreted as a valid link token and vice
 * versa. The secret is injected so the signer stays a pure, unit-testable
 * primitive.
 */
final class OAuthLinkTokenSigner
{
    /** Domain-separation tag, the payload's first field, distinguishing link tokens from state tokens. */
    private const string DOMAIN_TAG = 'oauth-link';

    private const string FIELD_SEPARATOR = '|';
    private const string PART_SEPARATOR = '.';
    private const int FIELD_COUNT = 5;

    /**
     * @param string $secret HMAC signing secret (env-sourced by the caller)
     */
    public function __construct(
        private readonly string $secret,
    ) {
    }

    /**
     * Mints a signed link token for a pending `(provider, subject)` account, valid for `ttlSeconds`.
     *
     * @param string $provider Provider key the pending link belongs to
     * @param string $subject Provider-immutable account id to link
     * @param string $email Provider-reported email that collided with a verified identity
     * @param int $ttlSeconds Lifetime in seconds from now
     * @return string Signed link token
     */
    public function issue(string $provider, string $subject, string $email, int $ttlSeconds): string
    {
        $expiry = time() + $ttlSeconds;

        $payload = implode(self::FIELD_SEPARATOR, [self::DOMAIN_TAG, $provider, $subject, $email, $expiry]);
        $encodedPayload = $this->base64UrlEncode($payload);

        return $encodedPayload . self::PART_SEPARATOR . $this->sign($encodedPayload);
    }

    /**
     * Verifies a link token and returns its capability, or null when it does not hold.
     *
     * Returns null (never throws) on any failure — a malformed token, a bad
     * signature, a wrong domain tag, an empty field, or an expired token — so the
     * caller can answer a bad link generically without branching on a cause the
     * wire must never disclose.
     *
     * @param string $token Signed link token to verify
     * @return ?OAuthLinkTokenData Decoded capability, or null when the token is invalid or expired
     */
    public function verify(string $token): ?OAuthLinkTokenData
    {
        $parts = explode(self::PART_SEPARATOR, $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;
        if (!hash_equals($this->sign($encodedPayload), $signature)) {
            return null;
        }

        $payload = $this->base64UrlDecode($encodedPayload);
        if ($payload === null) {
            return null;
        }

        $fields = explode(self::FIELD_SEPARATOR, $payload);
        if (count($fields) !== self::FIELD_COUNT) {
            return null;
        }

        [$domainTag, $provider, $subject, $email, $expiry] = $fields;
        if (!hash_equals(self::DOMAIN_TAG, $domainTag)) {
            return null;
        }
        if ($provider === '' || $subject === '' || $email === '') {
            return null;
        }
        if (time() > (int)$expiry) {
            return null;
        }

        return new OAuthLinkTokenData($provider, $subject, $email);
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
