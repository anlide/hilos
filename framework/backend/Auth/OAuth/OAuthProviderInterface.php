<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * The seam every OAuth provider implements (HIL-281).
 *
 * Splits the provider knowledge (endpoint URLs, credentials, redirect) from the
 * way the exchange is driven: the framework drives only an
 * {@see HttpOAuthProvider}, which yields HTTP requests the async agent replays
 * over non-blocking sockets. This base carries what needs no I/O — the key and
 * the authorize URL, both produced synchronously in the start handler.
 */
interface OAuthProviderInterface
{
    /**
     * Returns the stable provider key, e.g. 'oauth:github'.
     *
     * @return string Provider key
     */
    public function getKey(): string;

    /**
     * Builds the provider authorization URL the browser is sent to.
     *
     * Carries the client id, the SPA redirect URI, the scope, `response_type=code`
     * and the signed `state`. Synchronous and side-effect free.
     *
     * @param string $state Signed state token to round-trip through the provider
     * @return string Absolute authorization URL
     */
    public function buildAuthorizeUrl(string $state): string;
}
