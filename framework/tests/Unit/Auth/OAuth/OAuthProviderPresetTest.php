<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OAuth recipes the framework ships (HIL-419).
 *
 * A recipe is what a project no longer writes down, so nothing in a project
 * fails when one of these values is wrong - the failure surfaces at a provider's
 * endpoint, in an environment where the credentials are real. The field map and
 * the scope are pinned here for that reason, and the last test pins the thing a
 * preset must NOT do: close the door on a provider the framework never heard of.
 *
 * OAUTH_ENDPOINT_URL is pinned by every case (HIL-924): empty, the recipes above
 * are the provider's own endpoints; set, the stand's emulator takes all three. The
 * process environment is what the accessor reads first, so a case sets it there
 * and the value the suite ran with is put back afterwards.
 */
final class OAuthProviderPresetTest extends TestCase
{
    private const string CLIENT_ID = 'client-123';
    private const string CLIENT_SECRET = 'secret-xyz';
    private const string REDIRECT_URI = 'https://app.example/auth/callback';
    private const string EMULATOR_URL = 'https://stand-gateway:18000/oauth';

    /** @var string|false OAUTH_ENDPOINT_URL the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousEndpointUrl = false;

    protected function setUp(): void
    {
        $this->previousEndpointUrl = getenv(EnvConstants::OAUTH_ENDPOINT_URL->name);
        self::setEndpointUrl('');
    }

    protected function tearDown(): void
    {
        $name = EnvConstants::OAUTH_ENDPOINT_URL->name;
        $this->previousEndpointUrl === false ? putenv($name) : putenv($name . '=' . $this->previousEndpointUrl);
    }

    /**
     * A case value is the provider key itself, in the `oauth:` form the wire and the DB store.
     */
    public function testCaseValueIsTheProviderKey(): void
    {
        self::assertSame(AuthMethodKey::OAUTH_PREFIX . 'github', OAuthProviderPreset::GITHUB->value);
        self::assertSame(AuthMethodKey::OAUTH_PREFIX . 'google', OAuthProviderPreset::GOOGLE->value);
    }

    /**
     * Every preset builds a config under its own key - the recipe never renames the provider.
     *
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testConfigCarriesThePresetKey(): void
    {
        foreach (OAuthProviderPreset::cases() as $preset) {
            self::assertSame($preset->value, $this->configOf($preset)->key);
        }
    }

    /**
     * GitHub's recipe: the endpoints, the scope, and the `login` name field HIL-573 settled on.
     *
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testGithubRecipeIsComplete(): void
    {
        $config = $this->configOf(OAuthProviderPreset::GITHUB);

        self::assertSame('https://github.com/login/oauth/authorize', $config->authorizeUrl);
        self::assertSame('https://github.com/login/oauth/access_token', $config->tokenUrl);
        self::assertSame('https://api.github.com/user', $config->userInfoUrl);
        self::assertSame('read:user user:email', $config->scope);
        self::assertSame('id', $config->subjectKey);
        self::assertSame('email', $config->emailKey);
        self::assertSame('login', $config->nameKey);
    }

    /**
     * Google's recipe: the OpenID userinfo endpoint, not the `id_token` the generic client cannot verify.
     *
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testGoogleRecipeIsComplete(): void
    {
        $config = $this->configOf(OAuthProviderPreset::GOOGLE);

        self::assertSame('https://accounts.google.com/o/oauth2/v2/auth', $config->authorizeUrl);
        self::assertSame('https://oauth2.googleapis.com/token', $config->tokenUrl);
        self::assertSame('https://openidconnect.googleapis.com/v1/userinfo', $config->userInfoUrl);
        self::assertSame('openid email profile', $config->scope);
        self::assertSame('sub', $config->subjectKey);
        self::assertSame('email', $config->emailKey);
        self::assertSame('name', $config->nameKey);
    }

    /**
     * A set OAUTH_ENDPOINT_URL takes all three endpoints of every preset, under the provider's name.
     *
     * The name is the emulator's profile, and nothing else of the recipe moves: the emulator
     * plays the live provider, not a different recipe.
     *
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testEndpointUrlRedirectsEveryPresetEndpoint(): void
    {
        self::setEndpointUrl(self::EMULATOR_URL);

        $github = $this->configOf(OAuthProviderPreset::GITHUB);
        self::assertSame(self::EMULATOR_URL . '/github/authorize', $github->authorizeUrl);
        self::assertSame(self::EMULATOR_URL . '/github/token', $github->tokenUrl);
        self::assertSame(self::EMULATOR_URL . '/github/userinfo', $github->userInfoUrl);
        self::assertSame('read:user user:email', $github->scope);
        self::assertSame('login', $github->nameKey);

        $google = $this->configOf(OAuthProviderPreset::GOOGLE);
        self::assertSame(self::EMULATOR_URL . '/google/authorize', $google->authorizeUrl);
        self::assertSame(self::EMULATOR_URL . '/google/token', $google->tokenUrl);
        self::assertSame(self::EMULATOR_URL . '/google/userinfo', $google->userInfoUrl);
        self::assertSame('openid email profile', $google->scope);
        self::assertSame('sub', $google->subjectKey);
    }

    /**
     * A trailing slash on the base is forgiven, as it is on any base URL.
     *
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testEndpointUrlTrailingSlashIsForgiven(): void
    {
        self::setEndpointUrl(self::EMULATOR_URL . '/');

        $config = $this->configOf(OAuthProviderPreset::GITHUB);

        self::assertSame(self::EMULATOR_URL . '/github/authorize', $config->authorizeUrl);
        self::assertSame(self::EMULATOR_URL . '/github/userinfo', $config->userInfoUrl);
    }

    /**
     * What the project passes in reaches the config untouched, secret included.
     *
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testClientDataIsPlacedAsGiven(): void
    {
        foreach (OAuthProviderPreset::cases() as $preset) {
            $config = $this->configOf($preset);

            self::assertSame(self::CLIENT_ID, $config->clientId);
            self::assertSame(self::CLIENT_SECRET, $config->clientSecret);
            self::assertSame(self::REDIRECT_URI, $config->redirectUri);
        }
    }

    /**
     * A provider the framework has no preset for still connects: a hand-built config
     * is a provider the registry accepts and finds under its own key.
     */
    public function testProviderBuiltWithoutAPresetIsStillRegistrable(): void
    {
        $key = AuthMethodKey::OAUTH_PREFIX . 'gitea';
        $registry = new OAuthProviderRegistry([
            new GenericOAuthProvider(new OAuthProviderConfig(
                key: $key,
                clientId: self::CLIENT_ID,
                clientSecret: self::CLIENT_SECRET,
                authorizeUrl: 'https://gitea.example/login/oauth/authorize',
                tokenUrl: 'https://gitea.example/login/oauth/access_token',
                userInfoUrl: 'https://gitea.example/api/v1/user',
                scope: 'read:user',
                redirectUri: self::REDIRECT_URI,
                subjectKey: 'id',
                emailKey: 'email',
                nameKey: 'login',
            )),
        ]);

        self::assertSame([$key], $registry->keys());
        self::assertSame($key, $registry->get($key)?->getKey());
    }

    /**
     * Points OAUTH_ENDPOINT_URL at one value for the rest of the case.
     *
     * @param string $endpointUrl Value the preset reads; empty keeps the provider's own endpoints
     */
    private static function setEndpointUrl(string $endpointUrl): void
    {
        putenv(EnvConstants::OAUTH_ENDPOINT_URL->name . '=' . $endpointUrl);
    }

    /**
     * @param OAuthProviderPreset $preset Preset under test
     * @return OAuthProviderConfig Config filled with this test's client data
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    private function configOf(OAuthProviderPreset $preset): OAuthProviderConfig
    {
        return $preset->config(self::CLIENT_ID, self::CLIENT_SECRET, self::REDIRECT_URI);
    }
}
