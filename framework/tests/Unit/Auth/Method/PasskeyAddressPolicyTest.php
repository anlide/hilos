<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method;

use Hilos\Auth\Method\PasskeyAddressPolicy;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestSettings;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for whether a passkey may start an account on an unconfirmed address (HIL-1105).
 *
 * The reader fails closed, so every case that is not an explicit yes answers no.
 */
final class PasskeyAddressPolicyTest extends TestCase
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
     * A process with no settings at all has allowed nothing.
     */
    public function testNoSettingsAnswersNo(): void
    {
        AuthMethodTestHilos::$setting = null;

        self::assertFalse(PasskeyAddressPolicy::allowsUnproven());
    }

    /**
     * A project whose catalog does not carry the key has allowed nothing.
     */
    public function testACatalogWithoutTheKeyAnswersNo(): void
    {
        AuthMethodTestHilos::$setting = new SettingsAccessor();

        self::assertFalse(PasskeyAddressPolicy::allowsUnproven());
    }

    /**
     * Nothing stored: the catalog default is no.
     */
    public function testTheDefaultIsNo(): void
    {
        self::assertFalse(PasskeyAddressPolicy::allowsUnproven());
    }

    /**
     * A stored yes allows it.
     */
    public function testAStoredYesAllowsIt(): void
    {
        AuthMethodTestSettings::$passkeyAllowsUnproven = true;

        self::assertTrue(PasskeyAddressPolicy::allowsUnproven());
    }

    /**
     * A stored no refuses it.
     */
    public function testAStoredNoRefusesIt(): void
    {
        AuthMethodTestSettings::$passkeyAllowsUnproven = false;

        self::assertFalse(PasskeyAddressPolicy::allowsUnproven());
    }

    /**
     * A stored value that is not a boolean cannot be read, and an unread policy answers no.
     */
    public function testAValueThatCannotBeReadAnswersNo(): void
    {
        AuthMethodTestSettings::$passkeyAllowsUnproven = 'maybe';

        self::assertFalse(PasskeyAddressPolicy::allowsUnproven());
    }
}
