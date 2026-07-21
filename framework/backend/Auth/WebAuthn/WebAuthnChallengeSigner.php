<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\Exception\WebAuthnChallengeException;
use Random\RandomException;

/**
 * Issues and verifies the stateless, signed WebAuthn challenge token (HIL-284).
 *
 * The replay guard of both ceremonies, kept storage-free by the same design as
 * {@see \Hilos\Auth\OAuth\OAuthStateSigner}: the token is
 * `base64url(payload).base64url(HMAC)` where the payload binds the random
 * challenge, the ceremony purpose (register/login), the initiating session
 * token, an optional user id, and an absolute expiry. On confirm the HMAC is
 * recomputed in constant time and purpose/session/expiry are checked; the caller
 * then compares the recovered {@see WebAuthnChallengeClaims::$challenge} against
 * the challenge echoed in clientDataJSON. Single-use is best-effort via the
 * short TTL — no server-side replay ledger at this leaf, per the settled
 * contract.
 *
 * The signing secret is injected (the config reads it from env) so the signer
 * stays a pure, unit-testable primitive.
 */
final class WebAuthnChallengeSigner
{
    public const string PURPOSE_REGISTER = 'register';
    public const string PURPOSE_LOGIN = 'login';

    private const int CHALLENGE_BYTES = 32;
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
     * Mints a random challenge bound to a purpose/session/user, valid for `ttlSeconds`.
     *
     * @param string $purpose Ceremony purpose (PURPOSE_REGISTER | PURPOSE_LOGIN)
     * @param string $sessionToken Initiating session token to bind
     * @param ?int $userId User to bind (register), or null (login)
     * @param int $ttlSeconds Lifetime in seconds from now
     * @return WebAuthnChallenge The client challenge value and its signed token
     * @throws RandomException When the platform CSPRNG cannot produce a challenge
     */
    public function issue(string $purpose, string $sessionToken, ?int $userId, int $ttlSeconds): WebAuthnChallenge
    {
        $challenge = Base64Url::encode(random_bytes(self::CHALLENGE_BYTES));
        $expiry = time() + $ttlSeconds;

        $payload = implode(self::FIELD_SEPARATOR, [
            $challenge,
            $purpose,
            $sessionToken,
            $userId === null ? '' : (string)$userId,
            $expiry,
        ]);
        $encodedPayload = Base64Url::encode($payload);
        $token = $encodedPayload . self::PART_SEPARATOR . $this->sign($encodedPayload);

        return new WebAuthnChallenge($challenge, $token);
    }

    /**
     * Verifies a challenge token against its purpose, initiating session and the clock.
     *
     * @param string $token Signed token returned on confirm
     * @param string $purpose Ceremony purpose the token must have been minted for
     * @param string $sessionToken Session token the confirm arrived on
     * @return WebAuthnChallengeClaims The recovered challenge and bound user id
     * @throws WebAuthnChallengeException When the token is malformed, has a bad
     *   signature, was minted for a different purpose/session, or has expired
     */
    public function verify(string $token, string $purpose, string $sessionToken): WebAuthnChallengeClaims
    {
        $parts = explode(self::PART_SEPARATOR, $token, 2);
        if (count($parts) !== 2) {
            throw new WebAuthnChallengeException('WebAuthn challenge is malformed');
        }

        [$encodedPayload, $signature] = $parts;
        if (!hash_equals($this->sign($encodedPayload), $signature)) {
            throw new WebAuthnChallengeException('WebAuthn challenge signature is invalid');
        }

        $payload = Base64Url::decode($encodedPayload);
        if ($payload === null) {
            throw new WebAuthnChallengeException('WebAuthn challenge payload is malformed');
        }

        $fields = explode(self::FIELD_SEPARATOR, $payload);
        if (count($fields) !== self::FIELD_COUNT) {
            throw new WebAuthnChallengeException('WebAuthn challenge payload is malformed');
        }

        [$challenge, $boundPurpose, $boundSessionToken, $userId, $expiry] = $fields;
        if (!hash_equals($boundPurpose, $purpose)) {
            throw new WebAuthnChallengeException('WebAuthn challenge is bound to a different purpose');
        }

        if (!hash_equals($boundSessionToken, $sessionToken)) {
            throw new WebAuthnChallengeException('WebAuthn challenge is bound to a different session');
        }

        if (time() > (int)$expiry) {
            throw new WebAuthnChallengeException('WebAuthn challenge has expired');
        }

        return new WebAuthnChallengeClaims($challenge, $userId === '' ? null : (int)$userId);
    }

    /**
     * @param string $encodedPayload base64url-encoded payload to sign
     * @return string base64url-encoded HMAC-SHA256 signature
     */
    private function sign(string $encodedPayload): string
    {
        return Base64Url::encode(hash_hmac('sha256', $encodedPayload, $this->secret, true));
    }
}
