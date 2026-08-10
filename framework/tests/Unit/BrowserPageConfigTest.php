<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the single policy a page's browser declaration is now read by (HIL-549).
 *
 * Two states shared one spelling before: a page that names no signal, and a page that
 * names something which is not a signal. Both became the empty string, so a typo in a
 * declaration was indistinguishable from a page that has no browser data at all — and
 * the typo's only symptom was a page that quietly never updated.
 *
 * The line runs through what the field means, not through emptiness: naming no signal
 * is legal and is null, naming a non-signal is a broken declaration and is refused.
 */
final class BrowserPageConfigTest extends TestCase
{
    private const string SIGNAL = 'unit_browser_signal';

    public function testPageNamingNoSignalDeclaresNoSubscription(): void
    {
        $this->assertNull(BrowserPageConfig::fromArray([])->signalName);
    }

    public function testPageNamingASignalCarriesIt(): void
    {
        $config = BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]);

        $this->assertSame(self::SIGNAL, $config->signalName);
    }

    public function testNonStringSignalIsRefusedRatherThanReadAsNoSubscription(): void
    {
        $this->expectException(PageInternalErrorException::class);

        BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => ['not', 'a', 'name']]);
    }

    public function testRefusalMapsToAnInternalErrorSubscriptionCode(): void
    {
        try {
            BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 42]);
            $this->fail('A non-string signal must not be accepted.');
        } catch (PageInternalErrorException $e) {
            $this->assertSame(500, $e->httpCode);
            $this->assertSame('internal_error', $e->errorCode);
        }
    }
}
