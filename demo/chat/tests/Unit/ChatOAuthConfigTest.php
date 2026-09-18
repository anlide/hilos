<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Environment\ChatEnvCatalog;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for which OAuth providers the chat demo's wiring leaves standing (HIL-924).
 *
 * A provider is there exactly when its client pair is: an empty pair leaves it out on
 * every node alike, because no in-process stub stands in for it any more. A filled pair
 * builds the real provider over the framework's preset, and on the test stand that
 * preset is pointed at the stand's own provider emulator. A project reads its enabled
 * sign-in methods off this registry, so both halves are asserted.
 *
 * The case sets every variable it depends on in the process environment, which the
 * accessor reads first, and puts the suite's values back afterwards: the stand's CLI
 * container carries a filled pair of its own, and the answer must not depend on it.
 */
final class ChatOAuthConfigTest extends TestCase
{
    private const string EMULATOR_URL = 'https://stand-gateway:18000/oauth';
    private const string REDIRECT_URI = 'https://chat-nginx-test/auth/callback';

    private ?EnvAccessor $previousEnv = null;

    /** @var array<string, string|false> Variables this file sets, with the value each had before the case */
    private array $previousValues = [];

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        foreach (self::managedNames() as $name) {
            $this->previousValues[$name] = getenv($name);
        }

        self::setClientPairs('', '');
        putenv(EnvConstants::OAUTH_ENDPOINT_URL->name . '=');
        putenv(ChatEnvConstants::OAUTH_REDIRECT_URI . '=' . self::REDIRECT_URI);
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        foreach ($this->previousValues as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }

    /**
     * With credentials unset, a production node ends up with no OAuth provider at all.
     */
    public function testUnsetCredentialsLeaveNoProvidersOnProduction(): void
    {
        self::setAppEnv('prod');

        self::assertSame([], ChatOAuthConfig::buildProviderRegistry()->keys());
    }

    /**
     * The same unset credentials leave a dev node just as empty: no stub raises a provider there.
     */
    public function testUnsetCredentialsLeaveNoProvidersOnDev(): void
    {
        self::setAppEnv('dev');

        self::assertSame([], ChatOAuthConfig::buildProviderRegistry()->keys());
    }

    /**
     * Filled pairs build a real provider per preset, in icon order, aimed at the emulator.
     */
    public function testFilledCredentialsBuildRealProvidersOnTheEmulator(): void
    {
        self::setAppEnv('test');
        self::setClientPairs('stand-client', 'stand-secret');
        putenv(EnvConstants::OAUTH_ENDPOINT_URL->name . '=' . self::EMULATOR_URL);

        $registry = ChatOAuthConfig::buildProviderRegistry();

        self::assertSame(
            [OAuthProviderPreset::GITHUB->value, OAuthProviderPreset::GOOGLE->value],
            $registry->keys(),
        );
        foreach (['github' => OAuthProviderPreset::GITHUB, 'google' => OAuthProviderPreset::GOOGLE] as $profile => $preset) {
            $provider = $registry->get($preset->value);
            self::assertInstanceOf(GenericOAuthProvider::class, $provider);
            self::assertStringStartsWith(
                self::EMULATOR_URL . '/' . $profile . '/authorize?',
                $provider->buildAuthorizeUrl('state-1'),
            );
        }
    }

    /**
     * Points the node at one APP_ENV value for the duration of a case.
     *
     * @param string $appEnv Value APP_ENV resolves to while the registry is built
     */
    private static function setAppEnv(string $appEnv): void
    {
        putenv('APP_ENV=' . $appEnv);
        Hilos::$env = new EnvAccessor(ChatEnvCatalog::class);
    }

    /**
     * Gives both providers the same client pair.
     *
     * @param string $clientId Client id of each provider; empty leaves it unset
     * @param string $clientSecret Client secret of each provider; empty leaves it unset
     */
    private static function setClientPairs(string $clientId, string $clientSecret): void
    {
        putenv(ChatEnvConstants::OAUTH_GITHUB_CLIENT_ID . '=' . $clientId);
        putenv(ChatEnvConstants::OAUTH_GITHUB_CLIENT_SECRET . '=' . $clientSecret);
        putenv(ChatEnvConstants::OAUTH_GOOGLE_CLIENT_ID . '=' . $clientId);
        putenv(ChatEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET . '=' . $clientSecret);
    }

    /**
     * Every variable a case sets, so all of them are put back.
     *
     * @return list<string> Environment variable names
     */
    private static function managedNames(): array
    {
        return [
            'APP_ENV',
            ChatEnvConstants::OAUTH_GITHUB_CLIENT_ID,
            ChatEnvConstants::OAUTH_GITHUB_CLIENT_SECRET,
            ChatEnvConstants::OAUTH_GOOGLE_CLIENT_ID,
            ChatEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET,
            ChatEnvConstants::OAUTH_REDIRECT_URI,
            EnvConstants::OAUTH_ENDPOINT_URL->name,
        ];
    }
}
