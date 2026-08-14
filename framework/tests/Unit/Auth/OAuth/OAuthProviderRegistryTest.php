<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\StubOAuthProvider;
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
            new StubOAuthProvider(StubOAuthProvider::DEFAULT_KEY, self::REDIRECT_URI),
            new StubOAuthProvider(self::SECOND_KEY, self::REDIRECT_URI),
        ]);

        self::assertSame([StubOAuthProvider::DEFAULT_KEY, self::SECOND_KEY], $registry->keys());
    }

    /**
     * A listed key is already an auth method key: it carries the `oauth:` prefix as stored.
     */
    public function testListedKeyIsUsableAsAnAuthMethodKey(): void
    {
        $registry = new OAuthProviderRegistry([
            new StubOAuthProvider(StubOAuthProvider::DEFAULT_KEY, self::REDIRECT_URI),
        ]);

        foreach ($registry->keys() as $key) {
            self::assertStringStartsWith(AuthMethodKey::OAUTH_PREFIX, $key);
            self::assertTrue($registry->has($key));
        }
    }
}
