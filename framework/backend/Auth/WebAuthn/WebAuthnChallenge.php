<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

/**
 * A freshly minted WebAuthn challenge and its signed token (HIL-284).
 *
 * The pair an options handler returns: {@see $challenge} is the base64url value
 * placed into the client publicKey options (the authenticator signs over it and
 * echoes it in clientDataJSON), while {@see $token} is the stateless signed
 * envelope the client returns on confirm so the server can re-derive the same
 * challenge — plus its bound purpose, session and user — without any storage.
 */
final readonly class WebAuthnChallenge
{
    /**
     * @param string $challenge base64url challenge value for the client publicKey options
     * @param string $token Signed challenge token to hand back on confirm
     */
    public function __construct(
        public string $challenge,
        public string $token,
    ) {
    }
}
