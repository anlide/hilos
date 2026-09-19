<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

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
