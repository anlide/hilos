<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\IdentifierMask;
use Hilos\Sms\SmsText;
use PHPUnit\Framework\TestCase;

/**
 * What survives of an identifier once a log is allowed to carry it (HIL-607).
 *
 * The verification layer now writes a line for every consume it accepts or refuses,
 * which is the only way to answer "did the click arrive" without reading the table
 * by hand. The whole reason that line is allowed to exist is this masker: an
 * operator can tell two failures apart, and a leaked log is not a mailing list.
 *
 * So the property under test is not the exact asterisk count but the pair of
 * promises the masker makes — enough is kept to tell lines apart, and the address
 * itself never appears whole.
 */
final class IdentifierMaskTest extends TestCase
{
    private const string ADDRESS = 'flowuser218@example.test';

    /**
     * The shape the operator reads: one leading character, a fixed run, the tail,
     * and the domain untouched.
     */
    public function testAnAddressKeepsItsEdgesAndItsDomain(): void
    {
        self::assertSame('f****r218@example.test', IdentifierMask::mask(self::ADDRESS));
    }

    /** The point of the whole class: the address must not come back out of it. */
    public function testTheAddressNeverSurvivesWhole(): void
    {
        $masked = IdentifierMask::mask(self::ADDRESS);

        self::assertStringNotContainsString(self::ADDRESS, $masked);
        self::assertStringNotContainsString('flowuser', $masked);
    }

    /**
     * A local part with no middle to give up keeps nothing: a head and a tail that
     * together spell the whole thing would be a mask in name only.
     */
    public function testAShortLocalPartIsHiddenEntirely(): void
    {
        self::assertSame('****@example.test', IdentifierMask::mask('adam@example.test'));
    }

    /**
     * The run is a fixed width, so the masked form does not report how long the
     * hidden part was — length is half of what a guesser needs.
     */
    public function testTwoAddressesOfDifferentLengthMaskToTheSameWidth(): void
    {
        self::assertSame(
            mb_strlen(IdentifierMask::mask('abcdefgh1234@example.test')),
            mb_strlen(IdentifierMask::mask('abcdefghijklmnop1234@example.test')),
        );
    }

    /** A number is the case the project already had an answer for; it is reused. */
    public function testANumberIsMaskedTheWayTheSmsChannelMasksIt(): void
    {
        self::assertSame(SmsText::maskNumber('+15551234567'), IdentifierMask::mask('+15551234567'));
    }

    /**
     * Neither an address nor a number: nobody has vouched for the string, so none
     * of it is written. A masker that threw here would break the logging call it
     * was added to serve.
     */
    public function testAnUnrecognizableIdentifierIsHiddenEntirely(): void
    {
        self::assertSame('****', IdentifierMask::mask('not an identifier at all'));
    }
}
