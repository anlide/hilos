<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use Hilos\Notification\Delivery\ChannelConfigValidators;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the reusable channel config field validators (HIL-200).
 *
 * Locks the domain rules the admin channel-set action enforces before persisting an
 * override: TCP port range, mail security enum, and email address syntax. A valid
 * value (or an empty one, meaning "inherit") yields null; anything else yields a
 * caller-facing phrase.
 */
final class ChannelConfigValidatorsTest extends TestCase
{
    public function testPortAcceptsInRange(): void
    {
        self::assertNull(ChannelConfigValidators::port(1));
        self::assertNull(ChannelConfigValidators::port(587));
        self::assertNull(ChannelConfigValidators::port(65535));
    }

    public function testPortRejectsOutOfRange(): void
    {
        self::assertNotNull(ChannelConfigValidators::port(0));
        self::assertNotNull(ChannelConfigValidators::port(65536));
        self::assertNotNull(ChannelConfigValidators::port(-1));
    }

    public function testMailSecurityAcceptsKnownModes(): void
    {
        self::assertNull(ChannelConfigValidators::mailSecurity('starttls'));
        self::assertNull(ChannelConfigValidators::mailSecurity('tls'));
        self::assertNull(ChannelConfigValidators::mailSecurity('none'));
    }

    public function testMailSecurityRejectsUnknownMode(): void
    {
        self::assertNotNull(ChannelConfigValidators::mailSecurity('ssl'));
        self::assertNotNull(ChannelConfigValidators::mailSecurity(''));
    }

    public function testEmailAddressAcceptsValidOrEmpty(): void
    {
        self::assertNull(ChannelConfigValidators::emailAddress('noreply@example.com'));
        self::assertNull(ChannelConfigValidators::emailAddress(''));
    }

    public function testEmailAddressRejectsMalformed(): void
    {
        self::assertNotNull(ChannelConfigValidators::emailAddress('not-an-email'));
        self::assertNotNull(ChannelConfigValidators::emailAddress('a@b'));
    }
}
