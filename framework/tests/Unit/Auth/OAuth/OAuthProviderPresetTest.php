<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OAuth recipes the framework ships (HIL-419).
 *
 * A recipe is what a project no longer writes down, so nothing in a project
 * fails when one of these values is wrong - the failure surfaces at a provider's
 * endpoint, in an environment where the credentials are real. The field map and
 * the scope are pinned here for that reason, and the last test pins the thing a
 * preset must NOT do: close the door on a provider the framework never heard of.
 */
final class OAuthProviderPresetTest extends TestCase
{
    private const string CLIENT_ID = 'client-123';
    private const string CLIENT_SECRET = 'secret-xyz';
    private const string REDIRECT_URI = 'https://app.example/auth/callback';

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
     */
    public function testConfigCarriesThePresetKey(): void
    {
        foreach (OAuthProviderPreset::cases() as $preset) {
            self::assertSame($preset->value, $this->configOf($preset)->key);
        }
    }

    /**
     * GitHub's recipe: the endpoints, the scope, and the `login` name field HIL-573 settled on.
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
     * What the project passes in reaches the config untouched, secret included.
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
     * @param OAuthProviderPreset $preset Preset under test
     * @return OAuthProviderConfig Config filled with this test's client data
     */
    private function configOf(OAuthProviderPreset $preset): OAuthProviderConfig
    {
        return $preset->config(self::CLIENT_ID, self::CLIENT_SECRET, self::REDIRECT_URI);
    }
}
