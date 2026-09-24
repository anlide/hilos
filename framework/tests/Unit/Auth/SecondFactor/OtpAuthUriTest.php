<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\SecondFactor;

use Hilos\Auth\SecondFactor\OtpAuthUri;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the otpauth address an authenticator app reads from the QR code (HIL-494).
 */
final class OtpAuthUriTest extends TestCase
{
    /**
     * The label names the issuer and the account, and the parameters repeat what the codes are computed with.
     */
    public function testBuildsTheAddress(): void
    {
        self::assertSame(
            'otpauth://totp/Hilos%20Chat:ada%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Hilos%20Chat'
                . '&algorithm=SHA1&digits=6&period=30',
            OtpAuthUri::build('Hilos Chat', 'ada@example.com', 'JBSWY3DPEHPK3PXP'),
        );
    }

    /**
     * A colon in the issuer cannot pose as the label separator, and a plus in a phone number stays a plus.
     */
    public function testAColonInTheIssuerIsEscaped(): void
    {
        self::assertStringStartsWith(
            'otpauth://totp/A%3AB:%2B48123456789?',
            OtpAuthUri::build('A:B', '+48123456789', 'JBSWY3DPEHPK3PXP'),
        );
    }
}
