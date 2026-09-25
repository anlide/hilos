<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Http;

use Hilos\Core\Http\DeviceName;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared device label derived from a User-Agent.
 */
final class DeviceNameTest extends TestCase
{
    public function testReadsTheCommonDesktopAndMobileAgents(): void
    {
        self::assertSame(
            'Chrome on macOS',
            DeviceName::fromUserAgent(
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ),
        );
        self::assertSame(
            'Safari on iPhone',
            DeviceName::fromUserAgent(
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
                . '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            ),
        );
        self::assertSame(
            'Firefox on Windows',
            DeviceName::fromUserAgent(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            ),
        );
    }

    public function testTheMoreSpecificBrowserTokenWins(): void
    {
        self::assertSame(
            'Edge on Windows',
            DeviceName::fromUserAgent(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            ),
        );
        self::assertSame(
            'Opera on Windows',
            DeviceName::fromUserAgent(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 OPR/105.0.0.0',
            ),
        );
        self::assertSame(
            'Chrome on iPhone',
            DeviceName::fromUserAgent(
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
                . '(KHTML, like Gecko) CriOS/120.0.0.0 Mobile/15E148 Safari/604.1',
            ),
        );
    }

    public function testTheMoreSpecificPlatformTokenWins(): void
    {
        self::assertSame(
            'Chrome on Android',
            DeviceName::fromUserAgent(
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            ),
        );
        self::assertSame(
            'Chrome on ChromeOS',
            DeviceName::fromUserAgent(
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ),
        );
    }

    public function testAnUnrecognizedAgentNamesNothing(): void
    {
        self::assertNull(DeviceName::fromUserAgent(null));
        self::assertNull(DeviceName::fromUserAgent(''));
        self::assertNull(DeviceName::fromUserAgent('   '));
        self::assertNull(DeviceName::fromUserAgent('curl/8.4.0'));
        self::assertNull(DeviceName::fromUserAgent('Chrome/120.0.0.0'));
    }
}
