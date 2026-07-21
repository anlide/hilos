<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Auth\OAuth\Exception\OAuthStateException;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Random\RandomException;

/**
 * The synchronous facade for the OAuth login flow (HIL-281).
 *
 * Owns the two side-effect-free steps that run inside page actions: minting the
 * authorize URL + signed state ({@see beginAuthorization()}, the start action),
 * and verifying the returned state ({@see verifyState()}, the callback action's
 * CSRF gate). It deliberately does NOT perform the token/userinfo exchange —
 * that is non-blocking work the OAuth async agent drives across ticks, resolving
 * a provider through {@see providerFor()}. Keeping the exchange out of the
 * facade is what lets the callback action stay synchronous while the network
 * round-trips run off the master.
 */
final class OAuthService
{
    /**
     * @param OAuthProviderRegistry $providers Configured provider registry
     * @param OAuthStateSigner $stateSigner Signer for the stateless state token
     * @param int $stateTtlSeconds Lifetime of a minted state token in seconds
     */
    public function __construct(
        private readonly OAuthProviderRegistry $providers,
        private readonly OAuthStateSigner $stateSigner,
        private readonly int $stateTtlSeconds,
    ) {
    }

    /**
     * Builds the authorization URL for a provider, binding a signed state to the
     * initiating session.
     *
     * @param string $providerKey Provider key, e.g. 'oauth:github'
     * @param string $sessionToken Initiating session token to bind into the state
     * @return string Absolute authorization URL the browser is sent to
     * @throws OAuthUnknownProviderException When the provider is not configured
     * @throws RandomException When the platform CSPRNG cannot produce a nonce
     */
    public function beginAuthorization(string $providerKey, string $sessionToken): string
    {
        $provider = $this->providerFor($providerKey);
        $state = $this->stateSigner->issue($sessionToken, $this->stateTtlSeconds);

        return $provider->buildAuthorizeUrl($state);
    }

    /**
     * Verifies a callback state against the initiating session (CSRF gate).
     *
     * @param string $state State token returned to the callback
     * @param string $sessionToken Session token the callback arrived on
     * @throws OAuthStateException When the state is malformed, has a bad
     *   signature, is bound to a different session, or has expired
     */
    public function verifyState(string $state, string $sessionToken): void
    {
        $this->stateSigner->verify($state, $sessionToken);
    }

    /**
     * Resolves a configured provider by key for the async exchange.
     *
     * @param string $providerKey Provider key, e.g. 'oauth:github'
     * @return OAuthProviderInterface Configured provider
     * @throws OAuthUnknownProviderException When the provider is not configured
     */
    public function providerFor(string $providerKey): OAuthProviderInterface
    {
        $provider = $this->providers->get($providerKey);
        if ($provider === null) {
            throw new OAuthUnknownProviderException("OAuth provider '{$providerKey}' is not configured");
        }

        return $provider;
    }
}
