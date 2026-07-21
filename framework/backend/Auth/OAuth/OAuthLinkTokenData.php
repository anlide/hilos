<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * The account-link capability decoded from a verified link token (HIL-282).
 *
 * The stateless output of {@see OAuthLinkTokenSigner::verify()}: the provider key
 * and immutable subject of the OAuth account waiting to be linked, plus the
 * provider-reported `email` that flagged the collision against an existing
 * verified identity. It is a short-lived capability, not an authorization: the
 * link action still proves the re-authenticated user owns `email` before binding
 * the `(provider, subject)` identity, so a replayed or stolen token grants
 * nothing on its own.
 */
final readonly class OAuthLinkTokenData
{
    /**
     * @param string $provider Provider key the pending link belongs to, e.g. 'oauth:github'
     * @param string $subject Provider-immutable account id to link (non-empty)
     * @param string $email Provider-reported email that collided with a verified identity
     */
    public function __construct(
        public string $provider,
        public string $subject,
        public string $email,
    ) {
    }
}
