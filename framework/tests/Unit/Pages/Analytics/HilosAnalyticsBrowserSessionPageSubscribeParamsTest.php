<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Analytics;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\Pages\Analytics\AbstractHilosAnalyticsBrowserSessionPage;
use Hilos\Pages\Analytics\DTO\HilosAnalyticsBrowserSessionPageSubscribeParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Numeric route parsing and the framework page's static declarations. */
final class HilosAnalyticsBrowserSessionPageSubscribeParamsTest extends TestCase
{
    /** A positive row number needs no database lookup to become a route DTO. */
    public function testPositiveSessionNumber(): void
    {
        self::assertSame(42, HilosAnalyticsBrowserSessionPageSubscribeParams::fromPageRouteParams(
            new PageRouteParams([HilosPageRouteParams::HILOS_ANALYTICS_BROWSER_SESSION_ID => '42']),
        )->browserSessionId);
    }

    /**
     * @param array<string, string> $params Malformed or absent route parameter
     * @param class-string<MissingPageRouteParamException|InvalidPageRouteParamException> $exception Exact refusal family
     */
    #[DataProvider('invalidRoutes')]
    public function testInvalidSessionNumber(array $params, string $exception): void
    {
        $this->expectException($exception);
        HilosAnalyticsBrowserSessionPageSubscribeParams::fromPageRouteParams(new PageRouteParams($params));
    }

    /** @return iterable<string, array{array<string, string>, class-string}> Invalid route forms and their precise refusals */
    public static function invalidRoutes(): iterable
    {
        yield 'missing' => [[], MissingPageRouteParamException::class];
        yield 'empty' => [[HilosPageRouteParams::HILOS_ANALYTICS_BROWSER_SESSION_ID => ''], MissingPageRouteParamException::class];
        foreach (['text', '1.5', '0', '-1'] as $value) {
            yield $value => [[HilosPageRouteParams::HILOS_ANALYTICS_BROWSER_SESSION_ID => $value], InvalidPageRouteParamException::class];
        }
    }

    /** The page inherits the admin route gate and declares only its subscription signal. */
    public function testPageDeclarationsAndCatalog(): void
    {
        self::assertSame('hilos_analytics_browser_session', AbstractHilosAnalyticsBrowserSessionPage::PAGE);
        self::assertSame('browserSessionId', HilosPageRouteParams::HILOS_ANALYTICS_BROWSER_SESSION_ID);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosAnalyticsBrowserSessionPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosAnalyticsBrowserSessionPage::REACH);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_BROWSER_SESSION,
        ], AbstractHilosAnalyticsBrowserSessionPage::BROWSER);
        self::assertSame('subscription_page_hilos_analytics_browser_session',
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_BROWSER_SESSION);
        self::assertSame([], AbstractHilosAnalyticsBrowserSessionPage::ACTIONS);
        self::assertSame([], AbstractHilosAnalyticsBrowserSessionPage::SIGNALS);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Browser session',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Actions recorded in one browser session, in source-time order.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_ANALYTICS,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION]);
        foreach (HilosPageCatalog::DASHBOARD_SECTIONS as $section) {
            self::assertNotContains(HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION, $section[PageCatalogConstants::SECTION_ITEMS]);
        }
        self::assertTrue(new HilosPageFactory($this->createStub(PageAgentInterface::class), Hilos::class)
            ->hasPage(HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION));
    }
}
