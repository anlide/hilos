<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\ChangeLog;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablePage;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogTablePageSubscribeParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Table-name route parsing and the framework page's static declarations. */
final class HilosChangeLogTablePageSubscribeParamsTest extends TestCase
{
    /** A table name needs no database lookup to become a route DTO; a record key beside it is not the server's. */
    public function testTableName(): void
    {
        self::assertSame('hilos_user', HilosChangeLogTablePageSubscribeParams::fromPageRouteParams(new PageRouteParams([
            HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID => 'hilos_user',
            'record' => '42',
        ]))->tableId);
    }

    /** @param array<string, string> $params Absent or empty route parameter */
    #[DataProvider('missingRoutes')]
    public function testMissingTableName(array $params): void
    {
        $this->expectException(MissingPageRouteParamException::class);
        HilosChangeLogTablePageSubscribeParams::fromPageRouteParams(new PageRouteParams($params));
    }

    /** @return iterable<string, array{array<string, string>}> Route forms without a table name */
    public static function missingRoutes(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [[HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID => '']];
    }

    /** The page inherits the admin route gate, declares only its subscription signal, and offers no actions. */
    public function testPageDeclarations(): void
    {
        self::assertSame('hilos_change_log_table', AbstractHilosChangeLogTablePage::PAGE);
        self::assertSame(HilosPageConstants::HILOS_CHANGE_LOG_TABLE, AbstractHilosChangeLogTablePage::PAGE);
        self::assertSame('tableId', HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID);
        self::assertSame('changeLogTable', AbstractHilosChangeLogTablePage::SECTION);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosChangeLogTablePage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosChangeLogTablePage::REACH);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE,
        ], AbstractHilosChangeLogTablePage::BROWSER);
        self::assertSame([], AbstractHilosChangeLogTablePage::ACTIONS);
        self::assertSame([], AbstractHilosChangeLogTablePage::SIGNALS);
    }
}
