<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for enumerating the configured OAuth providers (HIL-414).
 *
 * The registry gained a way to be listed so a project can build the set of auth
 * methods it enables from the providers it already wired, instead of keeping a
 * second list of provider names beside this one. What that costs if it drifts is
 * an account whose provider button silently disappears, so the keys are asserted
 * to come back as configured — in order, and in the `oauth:` form a method key
 * is named with.
 */
final class OAuthProviderRegistryTest extends TestCase
{
    private const string REDIRECT_URI = 'https://app.example/auth/callback';
    private const string FIRST_KEY = 'oauth:github';
    private const string SECOND_KEY = 'oauth:gitlab';

    /**
     * An empty registry lists nothing rather than answering with a placeholder.
     */
    public function testEmptyRegistryListsNoKeys(): void
    {
        self::assertSame([], new OAuthProviderRegistry()->keys());
    }

    /**
     * Configured providers come back in configuration order, keyed as they were registered.
     */
    public function testConfiguredProvidersAreListedInOrder(): void
    {
        $registry = new OAuthProviderRegistry([
            new GenericOAuthProvider(self::providerConfig(self::FIRST_KEY)),
            new GenericOAuthProvider(self::providerConfig(self::SECOND_KEY)),
        ]);

        self::assertSame([self::FIRST_KEY, self::SECOND_KEY], $registry->keys());
    }

    /**
     * A listed key is already an auth method key: it carries the `oauth:` prefix as stored.
     */
    public function testListedKeyIsUsableAsAnAuthMethodKey(): void
    {
        $registry = new OAuthProviderRegistry([
            new GenericOAuthProvider(self::providerConfig(self::FIRST_KEY)),
        ]);

        foreach ($registry->keys() as $key) {
            self::assertStringStartsWith(AuthMethodKey::OAUTH_PREFIX, $key);
            self::assertTrue($registry->has($key));
        }
    }

    /**
     * Builds a provider's config, filled just enough to be registered and listed.
     *
     * @param string $key Provider key the config is registered under
     * @return OAuthProviderConfig Config for a provider under that key
     */
    private static function providerConfig(string $key): OAuthProviderConfig
    {
        return new OAuthProviderConfig(
            $key,
            'client-id',
            'client-secret',
            'https://provider.example/authorize',
            'https://provider.example/token',
            'https://provider.example/userinfo',
            'read:user',
            self::REDIRECT_URI,
            'id',
            'email',
            'name',
        );
    }
}
