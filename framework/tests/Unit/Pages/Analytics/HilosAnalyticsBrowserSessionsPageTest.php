<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Analytics;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Pages\Analytics\AbstractHilosAnalyticsBrowserSessionsPage;
use PHPUnit\Framework\TestCase;

/** Browser sessions uses the standard admin subscription without the overview's calendar payload. */
final class HilosAnalyticsBrowserSessionsPageTest extends TestCase
{
    /** Static identity, route access and source interest use the existing page contract. */
    public function testDeclarationsAndKnownPage(): void
    {
        self::assertSame('hilos_analytics_browser_sessions', AbstractHilosAnalyticsBrowserSessionsPage::PAGE);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosAnalyticsBrowserSessionsPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosAnalyticsBrowserSessionsPage::REACH);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_BROWSER_SESSIONS,
        ], AbstractHilosAnalyticsBrowserSessionsPage::BROWSER);
        self::assertSame(
            'subscription_page_hilos_analytics_browser_sessions',
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_BROWSER_SESSIONS,
        );
        self::assertSame([HilosDbContext::users], AbstractHilosAnalyticsBrowserSessionsPage::READS_DB);
        self::assertSame([], AbstractHilosAnalyticsBrowserSessionsPage::ACTIONS);
        self::assertSame([], AbstractHilosAnalyticsBrowserSessionsPage::SIGNALS);
        self::assertTrue(new HilosPageFactory($this->createStub(PageAgentInterface::class), Hilos::class)
            ->hasPage(HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS));
    }

    /** Even unrelated calendar params cannot invoke the overview snapshot from this page. */
    public function testOwnPayloadIsEmptyWithoutCalendarParamsOrDatabase(): void
    {
        $page = new class ($this->createStub(PageAgentInterface::class)) extends AbstractHilosAnalyticsBrowserSessionsPage {
            /**
             * @param PageRouteParams $params Route parameters independent of the table context
             * @return PagePayload Page-owned data before the catalog and table are composed
             */
            public function ownPayload(PageRouteParams $params): PagePayload
            {
                return $this->buildPagePayload('browser-sessions-test', $params);
            }
        };
        self::assertTrue($page->ownPayload(new PageRouteParams([]))->isEmpty());
        self::assertSame([], $page->ownPayload(new PageRouteParams([
            'period' => 'today', 'timeZone' => 'not-a-zone', 'hourStartTs' => '8640000000000000',
        ]))->toArray());
    }
}
