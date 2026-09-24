<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\SecondFactor;

use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the time-based one-time codes of the second factor (HIL-494).
 *
 * The vectors are RFC 6238's own (Appendix B, SHA1 column), truncated to the six digits the
 * framework issues: the RFC prints eight, and six are their last six.
 */
final class TotpTest extends TestCase
{
    /** The RFC's SHA1 test secret, as bytes. */
    private const string RFC_SECRET = '12345678901234567890';

    /**
     * @return array<string, array{int, string}> Unix time and the six-digit code of its step
     */
    public static function rfcVectors(): array
    {
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
        ];
    }

    /**
     * The code of each RFC moment is the RFC's.
     */
    #[DataProvider('rfcVectors')]
    public function testComputesTheRfcCodes(int $time, string $code): void
    {
        self::assertSame($code, Totp::codeAt(self::RFC_SECRET, Totp::stepAt($time)));
    }

    /**
     * A code checked at its own moment matches, and the answer is its step.
     */
    #[DataProvider('rfcVectors')]
    public function testVerifyAnswersTheMatchedStep(int $time, string $code): void
    {
        self::assertSame(Totp::stepAt($time), Totp::verify(Base32::encode(self::RFC_SECRET), $code, $time));
    }

    /**
     * The code of the step before and of the step after still match - a phone's clock drifts.
     */
    public function testAStepEitherSideIsAccepted(): void
    {
        $secret = Base32::encode(self::RFC_SECRET);
        $now = 1234567890;
        $step = Totp::stepAt($now);

        self::assertSame($step - 1, Totp::verify($secret, Totp::codeAt(self::RFC_SECRET, $step - 1), $now));
        self::assertSame($step + 1, Totp::verify($secret, Totp::codeAt(self::RFC_SECRET, $step + 1), $now));
    }

    /**
     * Two steps away is outside the window.
     */
    public function testTwoStepsAwayIsRefused(): void
    {
        $secret = Base32::encode(self::RFC_SECRET);
        $now = 1234567890;
        $step = Totp::stepAt($now);

        self::assertNull(Totp::verify($secret, Totp::codeAt(self::RFC_SECRET, $step - 2), $now));
        self::assertNull(Totp::verify($secret, Totp::codeAt(self::RFC_SECRET, $step + 2), $now));
    }

    /**
     * An app shows the code in two halves; the space between them does not matter.
     */
    public function testASpaceInTheTypedCodeIsIgnored(): void
    {
        self::assertSame(
            Totp::stepAt(59),
            Totp::verify(Base32::encode(self::RFC_SECRET), '287 082', 59),
        );
    }

    /**
     * Anything but six digits matches nothing, and a wrong code matches nothing.
     */
    public function testMalformedAndWrongCodesAreRefused(): void
    {
        $secret = Base32::encode(self::RFC_SECRET);

        self::assertNull(Totp::verify($secret, '28708', 59));
        self::assertNull(Totp::verify($secret, '2870821', 59));
        self::assertNull(Totp::verify($secret, 'abcdef', 59));
        self::assertNull(Totp::verify($secret, '287083', 59));
    }

    /**
     * A secret that is not base32 matches nothing instead of failing.
     */
    public function testAnUnreadableSecretMatchesNothing(): void
    {
        self::assertNull(Totp::verify('not base32!', '287082', 59));
    }

    /**
     * A fresh secret is twenty bytes of base32, and two of them differ.
     */
    public function testAFreshSecretIsTwentyBytes(): void
    {
        $secret = Totp::newSecret();

        self::assertSame(Totp::SECRET_BYTES, strlen((string)Base32::decode($secret)));
        self::assertNotSame($secret, Totp::newSecret());
    }
}
