<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Environment\NonProductionGate;

/**
 * The set of OAuth providers configured for a project (HIL-281).
 *
 * A typed boundary around the provider map so it is not carried as a raw array
 * through the service and agent. A project builds it from its provider catalog
 * (one {@see GenericOAuthProvider} per real provider, and a
 * {@see StubOAuthProvider} where the node is not production-like) and hands it
 * to the {@see OAuthService}; the async agent reads providers back out of it by
 * key.
 *
 * This constructor is also the door an offline provider never gets through on a
 * production node (HIL-671). It is the one narrow place a provider must pass to
 * reach anything: the service resolves logins through it, the agent exchanges
 * through it, and a project reads its enabled auth methods out of {@see keys()}.
 * A provider dropped here is therefore gone from all three at once, which no
 * project-side check could promise.
 */
final class OAuthProviderRegistry
{
    /** @var array<string, OAuthProviderInterface> Providers keyed by provider key */
    private array $providers = [];

    /** @var list<string> Keys of offline providers this node refused to register */
    private array $refusedOfflineKeys = [];

    /**
     * Registers every provider a production-like node is allowed to have.
     *
     * A real provider is indifferent to the environment and is always registered. An
     * offline one ({@see OfflineOAuthProvider}) hands out an identity with nothing
     * checked, so on a production-like node it is dropped and its key is remembered
     * instead. The rule is stated on the provider TYPE and not on empty credentials
     * because the type is what the framework can see: a project that forgot to fill
     * OAUTH_*_CLIENT_ID and a project that registered a stub by hand are the same
     * open door, and only one of them is visible from here.
     *
     * The verdict is asked once, above the loop: it describes the node, not a
     * provider, and the registry is rebuilt on every login action and every
     * identifier detection.
     *
     * @param list<OAuthProviderInterface> $providers Configured providers
     */
    public function __construct(array $providers = [])
    {
        $nonProduction = NonProductionGate::admitted();

        foreach ($providers as $provider) {
            if ($provider instanceof OfflineOAuthProvider && !$nonProduction) {
                $this->refusedOfflineKeys[] = $provider->getKey();
                continue;
            }

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

    /**
     * Lists the keys of the offline providers this node refused to register.
     *
     * The registry remembers the refusal but says nothing about it: it is rebuilt on
     * every login action and every identifier detection, so a warning from here would
     * reach a production log at the rate someone types into an input. The one caller
     * that builds it once per process, {@see AbstractOAuthAgent::onStart()}, is the one
     * that logs. Without that line a provider configured by a project would simply not
     * be there, and no one would know why.
     *
     * @return list<string> Refused provider keys, e.g. 'oauth:github'
     */
    public function refusedOfflineKeys(): array
    {
        return $this->refusedOfflineKeys;
    }
}
