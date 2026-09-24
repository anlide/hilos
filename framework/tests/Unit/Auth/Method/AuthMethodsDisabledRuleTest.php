<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodsDisabledRule;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\EnvConstants;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestProviderDirectory;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestUnservedHilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rule every write of the switched-off method list passes (HIL-427).
 *
 * The rule is what keeps an installation from locking itself out whichever door the value
 * comes through, so it is asked here directly, against the directory a demo declares. No
 * provider has a pair unless a case completes GitHub's, and no phone channel is configured.
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
        AuthMethodTestProviderDirectory::forgetGitHub();
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
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
     * One ready method left on is enough.
     */
    public function testOneReadyMethodLeftOnIsAccepted(): void
    {
        self::assertNull(AuthMethodsDisabledRule::validate(implode(',', [
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GITHUB->value,
            OAuthProviderPreset::GOOGLE->value,
        ])));
    }

    /**
     * A list that leaves on only methods nobody can come in through is refused (HIL-1080).
     */
    public function testLeavingOnlyUnreadyMethodsOnIsRefused(): void
    {
        $this->withoutMail();

        self::assertSame('At least one sign-in method that is set up must stay on', AuthMethodsDisabledRule::validate(implode(',', [
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
        ])));
    }

    /**
     * A provider whose client pair is complete is a way in, and may be the only one left on.
     */
    public function testAReadyProviderLeftOnIsAccepted(): void
    {
        $this->withoutMail();
        AuthMethodTestProviderDirectory::configureGitHub();

        self::assertNull(AuthMethodsDisabledRule::validate(implode(',', [
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GOOGLE->value,
        ])));
    }

    /**
     * An installation with no ready method has nothing to keep on but some method.
     */
    public function testWithNoReadyMethodOnlySwitchingEverythingOffIsRefused(): void
    {
        $this->withoutMail();
        AuthMethodTestUnservedHilos::mount(null);

        self::assertNull(AuthMethodsDisabledRule::validate(implode(',', [
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GITHUB->value,
        ])));
        self::assertSame('At least one sign-in method must stay on', AuthMethodsDisabledRule::validate(implode(',', [
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GITHUB->value,
            OAuthProviderPreset::GOOGLE->value,
        ])));
    }

    /**
     * A value that is not a string is not a list of keys.
     */
    public function testANonStringValueIsRefused(): void
    {
        self::assertSame('Unknown sign-in method: int', AuthMethodsDisabledRule::validate(3));
    }

    /**
     * Pins the mail transport to the file fallback with nowhere to write, so the mailed link is not ready.
     */
    private function withoutMail(): void
    {
        putenv(EnvConstants::MAIL_TRANSPORT->name);
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=');
    }
}
