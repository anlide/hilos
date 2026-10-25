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
use Hilos\Pages\Analytics\AbstractHilosAnalyticsPeoplePage;
use PHPUnit\Framework\TestCase;

/** People uses the standard admin subscription without the overview's calendar payload. */
final class HilosAnalyticsPeoplePageTest extends TestCase
{
    /** Static identity, route access and source interest use the existing page contract. */
    public function testDeclarationsAndKnownPage(): void
    {
        self::assertSame('hilos_analytics_people', AbstractHilosAnalyticsPeoplePage::PAGE);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosAnalyticsPeoplePage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosAnalyticsPeoplePage::REACH);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_PEOPLE,
        ], AbstractHilosAnalyticsPeoplePage::BROWSER);
        self::assertSame('subscription_page_hilos_analytics_people', HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_PEOPLE);
        self::assertSame([HilosDbContext::users], AbstractHilosAnalyticsPeoplePage::READS_DB);
        self::assertSame([], AbstractHilosAnalyticsPeoplePage::ACTIONS);
        self::assertSame([], AbstractHilosAnalyticsPeoplePage::SIGNALS);
        self::assertTrue(new HilosPageFactory($this->createStub(PageAgentInterface::class), Hilos::class)
            ->hasPage(HilosPageConstants::HILOS_ANALYTICS_PEOPLE));
    }

    /** Even unrelated calendar params cannot invoke the overview snapshot from this page. */
    public function testOwnPayloadIsEmptyWithoutCalendarParamsOrDatabase(): void
    {
        $page = new class ($this->createStub(PageAgentInterface::class)) extends AbstractHilosAnalyticsPeoplePage {
            /**
             * @param PageRouteParams $params Route parameters independent of the table context
             * @return PagePayload Page-owned data before the catalog and table are composed
             */
            public function ownPayload(PageRouteParams $params): PagePayload
            {
                return $this->buildPagePayload('people-test', $params);
            }
        };
        self::assertTrue($page->ownPayload(new PageRouteParams([]))->isEmpty());
        self::assertSame([], $page->ownPayload(new PageRouteParams([
            'period' => 'today', 'timeZone' => 'not-a-zone', 'hourStartTs' => '8640000000000000',
        ]))->toArray());
    }
}
