<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\StubOAuthProvider;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
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
 *
 * The same constructor is also the door an offline provider does not get through on a
 * production-like node (HIL-671), and those cases are here rather than in a file of
 * their own because it is one constructor deciding both: what the registry lists and
 * what it silently refused to list are the same answer read twice.
 */
final class OAuthProviderRegistryTest extends TestCase
{
    private const string REDIRECT_URI = 'https://app.example/auth/callback';
    private const string SECOND_KEY = 'oauth:gitlab';

    /** An APP_ENV value nobody declares, to prove the verdict fails closed rather than open. */
    private const string UNKNOWN_APP_ENV = 'weekend-box';

    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

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

    /**
     * On a production node the offline provider is not registered, and its key is remembered.
     *
     * The whole point of the leaf: a project that never filled its OAuth credentials used to
     * get a stub answering under the real provider's key, which hands an account to whoever
     * presses the button.
     */
    public function testProductionNodeRefusesAnOfflineProvider(): void
    {
        self::setAppEnv('prod');

        $registry = new OAuthProviderRegistry([
            new StubOAuthProvider(StubOAuthProvider::DEFAULT_KEY, self::REDIRECT_URI),
        ]);

        self::assertSame([], $registry->keys());
        self::assertSame([StubOAuthProvider::DEFAULT_KEY], $registry->refusedOfflineKeys());
    }

    /**
     * A real provider is indifferent to the environment and is registered on a production node.
     */
    public function testProductionNodeRegistersARealProvider(): void
    {
        self::setAppEnv('prod');

        $registry = new OAuthProviderRegistry([new GenericOAuthProvider(self::realProviderConfig())]);

        self::assertSame([self::SECOND_KEY], $registry->keys());
        self::assertSame([], $registry->refusedOfflineKeys());
    }

    /**
     * An APP_ENV nobody recognizes is refused like production, not admitted like a stand.
     *
     * The two mistakes do not cost the same: refusing on a stand costs a puzzled developer,
     * admitting on an unnamed node costs a sign-in that checked nothing.
     */
    public function testUnknownEnvironmentRefusesAnOfflineProvider(): void
    {
        self::setAppEnv(self::UNKNOWN_APP_ENV);

        $registry = new OAuthProviderRegistry([
            new StubOAuthProvider(StubOAuthProvider::DEFAULT_KEY, self::REDIRECT_URI),
        ]);

        self::assertSame([], $registry->keys());
        self::assertSame([StubOAuthProvider::DEFAULT_KEY], $registry->refusedOfflineKeys());
    }

    /**
     * A test node keeps its offline provider, which is what the auth e2e logs in through.
     */
    public function testTestEnvironmentKeepsAnOfflineProvider(): void
    {
        self::setAppEnv('test');

        $registry = new OAuthProviderRegistry([
            new StubOAuthProvider(StubOAuthProvider::DEFAULT_KEY, self::REDIRECT_URI),
        ]);

        self::assertSame([StubOAuthProvider::DEFAULT_KEY], $registry->keys());
        self::assertSame([], $registry->refusedOfflineKeys());
    }

    /**
     * Points the environment verdict at one APP_ENV value for the duration of a case.
     *
     * @param string $appEnv Value APP_ENV resolves to while the registry is built
     */
    private static function setAppEnv(string $appEnv): void
    {
        putenv('APP_ENV=' . $appEnv);
        Hilos::$env = new EnvAccessor();
    }

    /**
     * Builds a real provider's config, filled just enough to be registered and listed.
     *
     * @return OAuthProviderConfig Config for a non-offline provider under SECOND_KEY
     */
    private static function realProviderConfig(): OAuthProviderConfig
    {
        return new OAuthProviderConfig(
            self::SECOND_KEY,
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
