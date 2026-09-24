<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodReadiness;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\EnvConstants;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestProviderDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the one place that says whether an installation can serve a sign-in method (HIL-1080).
 *
 * The directories are the demo-shaped fixture's; no provider row is stored, so a provider's
 * pair comes from the env or from nowhere.
 */
final class AuthMethodReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AuthMethodTestHilos::mount(null);
    }

    protected function tearDown(): void
    {
        AuthMethodTestProviderDirectory::forgetGitHub();
        putenv(EnvConstants::MAIL_TRANSPORT->name);
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        AuthMethodTestHilos::unmount();

        parent::tearDown();
    }

    /**
     * A password and a passkey need nothing.
     */
    public function testAPasswordAndAPasskeyAreAlwaysReady(): void
    {
        self::assertTrue(AuthMethodReadiness::isReady(AuthMethodKey::PASSWORD));
        self::assertTrue(AuthMethodReadiness::isReady(AuthMethodKey::PASSKEY));
    }

    /**
     * The mailed link is ready exactly when a letter leaves the installation.
     */
    public function testTheMailedLinkFollowsTheMailTransport(): void
    {
        putenv(EnvConstants::MAIL_TRANSPORT->name . '=smtp');
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=relay.example.invalid');
        self::assertTrue(AuthMethodReadiness::isReady(AuthMethodKey::MAGIC_LINK));

        putenv(EnvConstants::MAIL_TRANSPORT->name);
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=');
        self::assertFalse(AuthMethodReadiness::isReady(AuthMethodKey::MAGIC_LINK));
    }

    /**
     * A provider without its client pair cannot sign anybody in.
     */
    public function testAProviderWithoutItsPairIsNotReady(): void
    {
        self::assertFalse(AuthMethodReadiness::isReady(OAuthProviderPreset::GITHUB->value));
        self::assertFalse(AuthMethodReadiness::isReady(OAuthProviderPreset::GOOGLE->value));
    }

    /**
     * A provider whose pair resolves from the env is ready.
     */
    public function testAProviderWithItsPairInTheEnvIsReady(): void
    {
        AuthMethodTestProviderDirectory::configureGitHub();

        self::assertTrue(AuthMethodReadiness::isReady(OAuthProviderPreset::GITHUB->value));
        self::assertFalse(AuthMethodReadiness::isReady(OAuthProviderPreset::GOOGLE->value));
    }

    /**
     * A provider key the directory does not declare names nothing that could serve.
     */
    public function testAnUndeclaredProviderIsNotReady(): void
    {
        self::assertFalse(AuthMethodReadiness::isReady('oauth:acme'));
    }

    /**
     * Narrowing keeps the ready keys in the order given.
     */
    public function testReadyKeysKeepTheOrderGiven(): void
    {
        AuthMethodTestProviderDirectory::configureGitHub();

        self::assertSame(
            [OAuthProviderPreset::GITHUB->value, AuthMethodKey::PASSWORD],
            AuthMethodReadiness::readyKeys([
                OAuthProviderPreset::GOOGLE->value,
                OAuthProviderPreset::GITHUB->value,
                AuthMethodKey::PASSWORD,
            ]),
        );
    }
}
