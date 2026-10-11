<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Core\Analytics\AnalyticsBrowserLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Browser and system recognition keeps overlapping user-agent tokens in precedence order. */
final class AnalyticsBrowserLabelTest extends TestCase
{
    /**
     * @param string $userAgent Raw browser input
     * @param string $expected Short recognized label
     */
    #[DataProvider('recognizedAgents')]
    public function testRecognizedParts(string $userAgent, string $expected): void
    {
        self::assertSame($expected, AnalyticsBrowserLabel::fromUserAgent($userAgent));
    }

    /** @return iterable<string, array{string, string}> Browser and operating-system examples */
    public static function recognizedAgents(): iterable
    {
        yield 'Edge before Chrome' => ['Windows NT 10.0 Chrome/128.0.0.0 Safari/537.36 Edg/129.0', 'Edge 129 · Windows'];
        yield 'Opera before Chrome' => ['Windows NT 10.0 Chrome/128.0 Safari/537.36 OPR/114.2', 'Opera 114 · Windows'];
        yield 'Samsung before Chrome' => ['Linux; Android 14 SamsungBrowser/26.0 Chrome/124.0', 'Samsung Internet 26 · Android'];
        yield 'Firefox on Linux' => ['X11; Linux x86_64 Firefox/130.0', 'Firefox 130 · Linux'];
        yield 'Firefox on iPhone' => ['iPhone; CPU iPhone OS 18_0 like Mac OS X FxiOS/131.1', 'Firefox 131 · iOS'];
        yield 'Chrome on ChromeOS' => ['X11; CrOS x86_64 15917.0.0 Chrome/128.0.0.0', 'Chrome 128 · ChromeOS'];
        yield 'Chrome on iPad' => ['iPad; CPU OS 18_0 like Mac OS X CriOS/129.2 Safari/604.1', 'Chrome 129 · iOS'];
        yield 'Safari on macOS' => ['Macintosh; Intel Mac OS X 14_6 Version/17.6 Safari/605.1.15', 'Safari 17 · macOS'];
        yield 'browser only' => ['Chrome/128.2.3', 'Chrome 128'];
        yield 'system only' => ['Windows NT 10.0', 'Windows'];
        yield 'missing version' => ['Edg/ (Android)', 'Edge · Android'];
        yield 'Safari requires Version' => ['Safari/605.1', 'Safari/605.1'];
        yield 'Version requires Safari' => ['Version/17.6', 'Version/17.6'];
        yield 'both Safari tokens regardless of order' => ['Safari/605.1 Version/17.6', 'Safari 17'];
    }

    public function testUnrecognizedInputIsTrimmedAndCappedByCharacters(): void
    {
        self::assertSame(str_repeat('é', 60), AnalyticsBrowserLabel::fromUserAgent('  ' . str_repeat('é', 80) . '  '));
        self::assertSame('Odd browser', AnalyticsBrowserLabel::fromUserAgent('  Odd browser  '));
        self::assertSame('', AnalyticsBrowserLabel::fromUserAgent('  '));
    }
}
