<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * The set of OAuth providers configured for a project (HIL-281).
 *
 * A typed boundary around the provider map so it is not carried as a raw array
 * through the service and agent. A project builds it from its provider catalog
 * (one {@see GenericOAuthProvider} per real provider, plus a
 * {@see StubOAuthProvider} in dev/e2e) and hands it to the {@see OAuthService};
 * the async agent reads providers back out of it by key.
 */
final class OAuthProviderRegistry
{
    /** @var array<string, OAuthProviderInterface> Providers keyed by provider key */
    private array $providers = [];

    /**
     * @param list<OAuthProviderInterface> $providers Configured providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getKey()] = $provider;
        }
    }

    /**
     * Returns the provider for a key, or null when none is configured.
     *
     * @param string $key Provider key, e.g. 'oauth:github'
     * @return ?OAuthProviderInterface Configured provider or null
     */
    public function get(string $key): ?OAuthProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * Reports whether a provider is configured for a key.
     *
     * @param string $key Provider key, e.g. 'oauth:github'
     * @return bool True when a provider is configured
     */
    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * Lists the keys of every configured provider, in configuration order.
     *
     * A provider key doubles as an auth method key (HIL-414): the identifier-first
     * surface names an account's providers one by one, and a project builds the set
     * of methods it enables from this list. Without it that set would be a second
     * hand-kept list of providers next to this one, and the two would drift.
     *
     * @return list<string> Provider keys, e.g. 'oauth:github'
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
