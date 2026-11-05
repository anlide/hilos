<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OAuth provider directory (HIL-286).
 *
 * A project composes its providers onto the framework's empty base with `array_replace`,
 * and the directory hands them back keyed by the descriptor's own key, in the order the
 * project wrote them - which is the order of the sign-in icons.
 */
final class OAuthProviderDirectoryTest extends TestCase
{
    public function testTheFrameworkBaseDeclaresNoProvider(): void
    {
        self::assertSame([], EmptyTestOAuthProviderDirectory::all());
        self::assertNull(EmptyTestOAuthProviderDirectory::redirectUriEnvKey());
    }

    public function testProvidersComeBackInTheOrderTheProjectWroteThem(): void
    {
        self::assertSame(
            [OAuthProviderPreset::GOOGLE->value, OAuthProviderPreset::GITHUB->value],
            array_keys(ProjectTestOAuthProviderDirectory::all()),
        );
    }

    public function testAProviderIsKeyedByItsOwnKeyAndNotByTheMapItCameFrom(): void
    {
        $github = ProjectTestOAuthProviderDirectory::get(OAuthProviderPreset::GITHUB->value);

        self::assertNotNull($github);
        self::assertSame('GitHub', $github->label);
        self::assertSame(OAuthProviderPreset::GITHUB, $github->preset);
        self::assertNull(ProjectTestOAuthProviderDirectory::get('mistyped'));
    }

    public function testAnUndeclaredProviderIsUnknown(): void
    {
        self::assertNull(ProjectTestOAuthProviderDirectory::get('oauth:gitlab'));
    }

    public function testKnownPresetNamesResolveToTheirDescriptors(): void
    {
        $github = ProjectTestOAuthProviderDirectory::getByName('github');
        self::assertNotNull($github);
        self::assertSame(OAuthProviderPreset::GITHUB->value, $github->key);
        self::assertSame(OAuthProviderPreset::GITHUB, $github->preset);

        $google = ProjectTestOAuthProviderDirectory::getByName('google');
        self::assertNotNull($google);
        self::assertSame(OAuthProviderPreset::GOOGLE->value, $google->key);
        self::assertSame(OAuthProviderPreset::GOOGLE, $google->preset);
    }

    public function testUnknownEmptyAndFullKeyNamesDoNotResolveAsPresets(): void
    {
        self::assertNull(NameTestOAuthProviderDirectory::getByName(''));
        self::assertNull(NameTestOAuthProviderDirectory::getByName('gitlab'));
        self::assertNull(NameTestOAuthProviderDirectory::getByName('oauth:github'));
    }

    public function testAnExactCustomNameWithCaseAndDotResolves(): void
    {
        $provider = NameTestOAuthProviderDirectory::getByName('Acme.sso');
        self::assertNotNull($provider);
        self::assertSame(AuthMethodKey::OAUTH_PREFIX . 'Acme.sso', $provider->key);
        self::assertNull(NameTestOAuthProviderDirectory::getByName('acme.sso'));
    }
}

/**
 * The framework base as a project that declares nothing would see it.
 */
final class EmptyTestOAuthProviderDirectory extends OAuthProviderDirectory
{
}

/**
 * A project directory with two presets, one of them under a mistyped map key.
 */
final class ProjectTestOAuthProviderDirectory extends OAuthProviderDirectory
{
    /**
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return array_replace(parent::providers(), [
            OAuthProviderPreset::GOOGLE->value => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GOOGLE, 'Google'),
            'mistyped' => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GITHUB, 'GitHub'),
        ]);
    }
}

/**
 * A directory with a provider whose key is only the OAuth prefix and an exact custom name.
 */
final class NameTestOAuthProviderDirectory extends OAuthProviderDirectory
{
    /**
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return array_replace(parent::providers(), [
            'empty-name' => OAuthProviderDescriptor::fromRecipe(self::recipe(AuthMethodKey::OAUTH_PREFIX), 'Empty name'),
            'custom-name' => OAuthProviderDescriptor::fromRecipe(
                self::recipe(AuthMethodKey::OAUTH_PREFIX . 'Acme.sso'),
                'Acme SSO',
            ),
        ]);
    }

    /**
     * @param string $key OAuth provider key for the test descriptor
     * @return OAuthProviderConfig Recipe for the test descriptor
     */
    private static function recipe(string $key): OAuthProviderConfig
    {
        return new OAuthProviderConfig(
            key: $key,
            clientId: '',
            clientSecret: '',
            authorizeUrl: '',
            tokenUrl: '',
            userInfoUrl: '',
            scope: '',
            redirectUri: '',
            subjectKey: '',
            emailKey: '',
            nameKey: '',
        );
    }
}
