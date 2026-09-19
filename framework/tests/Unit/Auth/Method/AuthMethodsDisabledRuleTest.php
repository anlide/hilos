<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodsDisabledRule;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rule every write of the switched-off method list passes (HIL-427).
 *
 * The rule is what keeps an installation from locking itself out whichever door the value
 * comes through, so it is asked here directly, against the directory a demo declares.
 */
final class AuthMethodsDisabledRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AuthMethodTestHilos::mount(null);
    }

    protected function tearDown(): void
    {
        AuthMethodTestHilos::unmount();

        parent::tearDown();
    }

    /**
     * An empty list switches nothing off, which is the default every installation starts on.
     */
    public function testAnEmptyListIsAccepted(): void
    {
        self::assertNull(AuthMethodsDisabledRule::validate(''));
    }

    /**
     * Wired keys are accepted in any order and with whitespace around them.
     */
    public function testWiredKeysAreAccepted(): void
    {
        self::assertNull(AuthMethodsDisabledRule::validate(' sms , password,' . OAuthProviderPreset::GOOGLE->value));
    }

    /**
     * A key the project never wired is refused by name.
     */
    public function testAnUnknownKeyIsRefusedByName(): void
    {
        self::assertSame(
            'Unknown sign-in method: oauth:acme',
            AuthMethodsDisabledRule::validate(AuthMethodKey::PASSWORD . ',oauth:acme'),
        );
    }

    /**
     * A list naming every wired method is refused: one way in has to stay.
     */
    public function testSwitchingEveryMethodOffIsRefused(): void
    {
        self::assertSame('At least one sign-in method must stay on', AuthMethodsDisabledRule::validate(implode(',', [
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GITHUB->value,
            OAuthProviderPreset::GOOGLE->value,
        ])));
    }

    /**
     * One wired method left on is enough.
     */
    public function testOneMethodLeftOnIsAccepted(): void
    {
        self::assertNull(AuthMethodsDisabledRule::validate(implode(',', [
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GITHUB->value,
        ])));
    }

    /**
     * A value that is not a string is not a list of keys.
     */
    public function testANonStringValueIsRefused(): void
    {
        self::assertSame('Unknown sign-in method: int', AuthMethodsDisabledRule::validate(3));
    }
}
