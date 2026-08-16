<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\PasskeyDeviceName;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for naming a passkey after the device it was enrolled on (HIL-418).
 *
 * The interesting part is not that a User-Agent parses but that the vocabularies
 * OVERLAP: Edge and Opera both claim Chrome, Chrome claims Safari, and an Android
 * device also says Linux. Each case below is one of those disguises. Anything
 * unrecognized must resolve to null rather than to half a name — the profile
 * shows a generic row for null, and a lie for a guess.
 */
final class PasskeyDeviceNameTest extends TestCase
{
    /**
     * A browser that names itself plainly is read plainly.
     */
    public function testReadsTheCommonDesktopAndMobileAgents(): void
    {
        self::assertSame(
            'Chrome on macOS',
            PasskeyDeviceName::fromUserAgent(
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ),
        );
        self::assertSame(
            'Safari on iPhone',
            PasskeyDeviceName::fromUserAgent(
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
                . '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            ),
        );
        self::assertSame(
            'Firefox on Windows',
            PasskeyDeviceName::fromUserAgent(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            ),
        );
    }

    /**
     * Edge and Opera both carry `Chrome`, and Chrome carries `Safari`; the more
     * specific token has to win or every Chromium browser reports as Chrome.
     */
    public function testTheMoreSpecificBrowserTokenWins(): void
    {
        self::assertSame(
            'Edge on Windows',
            PasskeyDeviceName::fromUserAgent(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            ),
        );
        self::assertSame(
            'Opera on Windows',
            PasskeyDeviceName::fromUserAgent(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 OPR/105.0.0.0',
            ),
        );
        self::assertSame(
            'Chrome on iPhone',
            PasskeyDeviceName::fromUserAgent(
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
                . '(KHTML, like Gecko) CriOS/120.0.0.0 Mobile/15E148 Safari/604.1',
            ),
        );
    }

    /**
     * Android says Linux too, and an iPhone says `like Mac OS X`; the device the
     * person holds has to win over the kernel underneath it.
     */
    public function testTheMoreSpecificPlatformTokenWins(): void
    {
        self::assertSame(
            'Chrome on Android',
            PasskeyDeviceName::fromUserAgent(
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            ),
        );
        self::assertSame(
            'Chrome on ChromeOS',
            PasskeyDeviceName::fromUserAgent(
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ),
        );
    }

    /**
     * Nothing to read means no name: an empty, absent or foreign agent, and one
     * whose browser is known but whose platform is not.
     */
    public function testAnUnrecognizedAgentNamesNothing(): void
    {
        self::assertNull(PasskeyDeviceName::fromUserAgent(null));
        self::assertNull(PasskeyDeviceName::fromUserAgent(''));
        self::assertNull(PasskeyDeviceName::fromUserAgent('   '));
        self::assertNull(PasskeyDeviceName::fromUserAgent('curl/8.4.0'));
        self::assertNull(PasskeyDeviceName::fromUserAgent('Chrome/120.0.0.0'));
    }
}
