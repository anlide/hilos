<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\OAuthConfigField;
use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\OAuth\OAuthConfigSource;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Tests\Integration\OAuthProviderConfigIntegrationTest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OAuth config resolver's layers below the database (HIL-286).
 *
 * Covers what needs no stored row: an env value wins over the recipe, an empty env value
 * says nothing and the recipe answers, the client secret is reported by source and state
 * and never by value, a provider with an incomplete pair builds no configuration, and the
 * registry keeps the directory's order. The stored layer - a row in hilos_oauth_provider
 * winning over env - needs a database and is proved by the integration case beside the
 * collection ({@see OAuthProviderConfigIntegrationTest}).
 *
 * The env variables are borrowed from the framework's own catalog: the resolver reads
 * whatever key a descriptor names, and a project's own keys are not in the stub catalog.
 */
final class OAuthConfigResolverTest extends TestCase
{
    /** Env variable standing in for a provider's client id. */
    public const EnvConstants CLIENT_ID_ENV = EnvConstants::MAIL_SMTP_USERNAME;

    /** Env variable standing in for a provider's client secret. */
    public const EnvConstants CLIENT_SECRET_ENV = EnvConstants::MAIL_SMTP_PASSWORD;

    /** Env variable standing in for the shared return address. */
    public const EnvConstants REDIRECT_ENV = EnvConstants::MAIL_FROM_NAME;

    /** Key of the hand-built provider the directory below declares. */
    public const string HAND_BUILT_KEY = 'oauth:acme';

    private ?EnvAccessor $previousEnv = null;

    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        // No database: no provider has a stored row.
        Hilos::$db = null;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        foreach ([self::CLIENT_ID_ENV, self::CLIENT_SECRET_ENV, self::REDIRECT_ENV] as $key) {
            putenv($key->name);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        parent::tearDown();
    }

    public function testClientIdComesFromEnvWhenSet(): void
    {
        putenv(self::CLIENT_ID_ENV->name . '=env-client');

        $resolved = $this->resolver()->resolve(self::github(), OAuthConfigField::CLIENT_ID);

        self::assertSame(OAuthConfigField::CLIENT_ID->value, $resolved->field);
        self::assertSame(OAuthConfigSource::ENV, $resolved->source);
        self::assertSame('env-client', $resolved->value);
        self::assertTrue($resolved->isSet);
    }

    public function testEmptyEnvLeavesThePresetClientIdUnset(): void
    {
        $resolved = $this->resolver()->resolve(self::github(), OAuthConfigField::CLIENT_ID);

        self::assertSame(OAuthConfigSource::DEFAULT, $resolved->source);
        self::assertSame('', $resolved->value);
        self::assertFalse($resolved->isSet);
    }

    public function testHandBuiltRecipeAnswersBelowEnv(): void
    {
        $resolved = $this->resolver()->resolve(self::handBuilt(), OAuthConfigField::CLIENT_ID);

        self::assertSame(OAuthConfigSource::DEFAULT, $resolved->source);
        self::assertSame('recipe-client', $resolved->value);
        self::assertTrue($resolved->isSet);
    }

    public function testScopeFallsToThePresetRecipe(): void
    {
        $resolved = $this->resolver()->resolve(self::github(), OAuthConfigField::SCOPE);

        self::assertSame(OAuthConfigSource::DEFAULT, $resolved->source);
        self::assertSame('read:user user:email', $resolved->value);
    }

    public function testSecretSetInEnvReportsItsSourceAndNeverItsValue(): void
    {
        putenv(self::CLIENT_SECRET_ENV->name . '=env-secret');

        $resolved = $this->resolver()->resolve(self::github(), OAuthConfigField::CLIENT_SECRET);

        self::assertSame(OAuthConfigSource::ENV, $resolved->source);
        self::assertNull($resolved->value);
        self::assertTrue($resolved->isSet);
    }

    public function testSecretSetNowhereReportsNotSet(): void
    {
        $resolved = $this->resolver()->resolve(self::github(), OAuthConfigField::CLIENT_SECRET);

        self::assertSame(OAuthConfigSource::DEFAULT, $resolved->source);
        self::assertNull($resolved->value);
        self::assertFalse($resolved->isSet);
    }

    public function testProviderWithoutItsSecretBuildsNoConfiguration(): void
    {
        putenv(self::CLIENT_ID_ENV->name . '=env-client');

        self::assertNull($this->resolver()->providerConfig(self::github()));
    }

    public function testCompletePairBuildsTheConfigurationTheExchangeRunsOn(): void
    {
        putenv(self::CLIENT_ID_ENV->name . '=env-client');
        putenv(self::CLIENT_SECRET_ENV->name . '=env-secret');
        putenv(self::REDIRECT_ENV->name . '=https://app.example/auth/callback');

        $config = $this->resolver()->providerConfig(self::github());

        self::assertNotNull($config);
        self::assertSame(OAuthProviderPreset::GITHUB->value, $config->key);
        self::assertSame('env-client', $config->clientId);
        self::assertSame('env-secret', $config->clientSecret);
        self::assertSame('read:user user:email', $config->scope);
        self::assertSame('https://app.example/auth/callback', $config->redirectUri);
        self::assertSame('https://github.com/login/oauth/authorize', $config->authorizeUrl);
    }

    public function testRegistryHoldsOnlyCompleteProvidersInDirectoryOrder(): void
    {
        putenv(self::CLIENT_ID_ENV->name . '=env-client');
        putenv(self::CLIENT_SECRET_ENV->name . '=env-secret');

        $registry = $this->resolver()->registry();

        // Google declares no env pair and has no row, so it is not offered.
        self::assertSame([self::HAND_BUILT_KEY, OAuthProviderPreset::GITHUB->value], $registry->keys());
    }

    public function testReturnAddressComesFromTheEnvTheDirectoryNames(): void
    {
        putenv(self::REDIRECT_ENV->name . '=https://app.example/auth/callback');

        $resolved = $this->resolver()->resolveRedirectUri();

        self::assertSame(OAuthConfigResolver::REDIRECT_URI_FIELD, $resolved->field);
        self::assertSame(OAuthConfigSource::ENV, $resolved->source);
        self::assertSame('https://app.example/auth/callback', $resolved->value);
    }

    public function testReturnAddressSetNowhereIsEmpty(): void
    {
        $resolved = $this->resolver()->resolveRedirectUri();

        self::assertSame(OAuthConfigSource::DEFAULT, $resolved->source);
        self::assertSame('', $resolved->value);
        self::assertFalse($resolved->isSet);
    }

    /**
     * @return OAuthConfigResolver Resolver over the directory below
     */
    private function resolver(): OAuthConfigResolver
    {
        return new OAuthConfigResolver(ResolverTestOAuthProviderDirectory::class);
    }

    /**
     * @return OAuthProviderDescriptor The GitHub preset with its pair in the stand-in env variables
     */
    public static function github(): OAuthProviderDescriptor
    {
        return OAuthProviderDescriptor::fromPreset(
            OAuthProviderPreset::GITHUB,
            'GitHub',
            self::CLIENT_ID_ENV,
            self::CLIENT_SECRET_ENV,
        );
    }

    /**
     * @return OAuthProviderDescriptor A provider Hilos ships no preset for, with its whole pair in its recipe
     */
    public static function handBuilt(): OAuthProviderDescriptor
    {
        return OAuthProviderDescriptor::fromRecipe(
            new OAuthProviderConfig(
                key: self::HAND_BUILT_KEY,
                clientId: 'recipe-client',
                clientSecret: 'recipe-secret',
                authorizeUrl: 'https://acme.example/authorize',
                tokenUrl: 'https://acme.example/token',
                userInfoUrl: 'https://acme.example/userinfo',
                scope: 'profile',
                redirectUri: '',
                subjectKey: 'sub',
                emailKey: 'email',
                nameKey: 'name',
            ),
            'Acme',
        );
    }
}

/**
 * A directory of three providers: a hand-built one, GitHub over env, and Google over nothing.
 */
final class ResolverTestOAuthProviderDirectory extends OAuthProviderDirectory
{
    /**
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return array_replace(parent::providers(), [
            OAuthConfigResolverTest::HAND_BUILT_KEY => OAuthConfigResolverTest::handBuilt(),
            OAuthProviderPreset::GITHUB->value => OAuthConfigResolverTest::github(),
            OAuthProviderPreset::GOOGLE->value => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GOOGLE, 'Google'),
        ]);
    }

    /**
     * @return EnvConstants Env variable standing in for the shared return address
     */
    public static function redirectUriEnvKey(): EnvConstants
    {
        return OAuthConfigResolverTest::REDIRECT_ENV;
    }
}
