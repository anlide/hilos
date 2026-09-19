<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the set an installation offers: the wired methods minus the switched-off ones (HIL-427).
 */
final class EnabledAuthMethodsTest extends TestCase
{
    protected function tearDown(): void
    {
        AuthMethodTestHilos::unmount();

        parent::tearDown();
    }

    /**
     * Nothing stored: every wired method is on, passkey included, in directory order.
     */
    public function testEveryWiredMethodIsOnByDefault(): void
    {
        AuthMethodTestHilos::mount(null);

        self::assertSame([
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GITHUB->value,
            OAuthProviderPreset::GOOGLE->value,
        ], EnabledAuthMethods::keys());
    }

    /**
     * Switched-off methods drop out and the rest keep directory order, whatever order was stored.
     */
    public function testSwitchedOffMethodsDropOutInDirectoryOrder(): void
    {
        AuthMethodTestHilos::mount(OAuthProviderPreset::GITHUB->value . ', password');

        self::assertSame([
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GOOGLE->value,
        ], EnabledAuthMethods::keys());
        self::assertFalse(EnabledAuthMethods::isEnabled(AuthMethodKey::PASSWORD));
        self::assertTrue(EnabledAuthMethods::isEnabled(AuthMethodKey::PASSKEY));
    }

    /**
     * A stored key the directory no longer names is skipped, not an error.
     */
    public function testAStoredKeyNobodyWiresIsSkipped(): void
    {
        AuthMethodTestHilos::mount('oauth:retired,' . AuthMethodKey::SMS);

        self::assertNotContains(AuthMethodKey::SMS, EnabledAuthMethods::keys());
        self::assertContains(AuthMethodKey::PASSWORD, EnabledAuthMethods::keys());
    }

    /**
     * An installation with no such setting has switched nothing off.
     */
    public function testNoSettingsMeansNothingIsSwitchedOff(): void
    {
        AuthMethodTestHilos::mount(null);
        AuthMethodTestHilos::$setting = null;

        self::assertContains(AuthMethodKey::PASSWORD, EnabledAuthMethods::keys());
    }

    /**
     * The wire form names each method, and a provider by the label its directory declares.
     */
    public function testTheWireFormNamesProvidersOnly(): void
    {
        AuthMethodTestHilos::mount(AuthMethodKey::MAGIC_LINK . ',' . AuthMethodKey::SMS);

        self::assertSame([
            ['key' => AuthMethodKey::PASSWORD, 'name' => null],
            ['key' => AuthMethodKey::PASSKEY, 'name' => null],
            ['key' => OAuthProviderPreset::GITHUB->value, 'name' => 'GitHub'],
            ['key' => OAuthProviderPreset::GOOGLE->value, 'name' => 'Google'],
        ], EnabledAuthMethods::toWire());
    }
}
