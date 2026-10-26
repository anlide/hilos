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
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Pages\Analytics\AbstractHilosAnalyticsPersonPage;
use Hilos\Pages\Analytics\DTO\HilosAnalyticsPersonPageSubscribeParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Numeric person-route parsing and the framework page's static declarations. */
final class HilosAnalyticsPersonPageSubscribeParamsTest extends TestCase
{
    /** A positive account number needs no database lookup to become a route DTO. */
    public function testPositiveAccountNumber(): void
    {
        self::assertSame(42, HilosAnalyticsPersonPageSubscribeParams::fromPageRouteParams(
            new PageRouteParams([HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => '42']),
        )->userId);
    }

    /**
     * @param array<string, string> $params Malformed or absent route parameter
     * @param class-string<MissingPageRouteParamException|InvalidPageRouteParamException> $exception Exact refusal family
     */
    #[DataProvider('invalidRoutes')]
    public function testInvalidAccountNumber(array $params, string $exception): void
    {
        $this->expectException($exception);
        HilosAnalyticsPersonPageSubscribeParams::fromPageRouteParams(new PageRouteParams($params));
    }

    /** @return iterable<string, array{array<string, string>, class-string}> Invalid route forms and their precise refusals */
    public static function invalidRoutes(): iterable
    {
        yield 'missing' => [[], MissingPageRouteParamException::class];
        yield 'empty' => [[HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => ''], MissingPageRouteParamException::class];
        foreach (['text', '1.5', '0', '-1', '7abc'] as $value) {
            yield $value => [[HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => $value], InvalidPageRouteParamException::class];
        }
    }

    /** The page inherits the admin route gate, declares its subscription signal, and reads the people it names. */
    public function testPageDeclarations(): void
    {
        self::assertSame('hilos_analytics_person', AbstractHilosAnalyticsPersonPage::PAGE);
        self::assertSame('userId', HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID);
        self::assertSame(HilosPageRouteParams::HILOS_USER_USER_ID, HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosAnalyticsPersonPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosAnalyticsPersonPage::REACH);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_PERSON,
        ], AbstractHilosAnalyticsPersonPage::BROWSER);
        self::assertSame('subscription_page_hilos_analytics_person', HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_PERSON);
        self::assertSame([HilosDbContext::users, HilosDbContext::userPhotos], AbstractHilosAnalyticsPersonPage::READS_DB);
        self::assertSame([], AbstractHilosAnalyticsPersonPage::ACTIONS);
        self::assertSame([], AbstractHilosAnalyticsPersonPage::SIGNALS);
        self::assertTrue(new HilosPageFactory($this->createStub(PageAgentInterface::class), Hilos::class)
            ->hasPage(HilosPageConstants::HILOS_ANALYTICS_PERSON));
    }
}
