<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\ChangeLog;

use DateTimeImmutable;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\ChangeLog\Section\ChangeLogNoisyColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogOverview;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\ChangeLogTables;
use Hilos\Hilos;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogDashboardPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogFeedPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogNoisyPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablesPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableColumnsPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableTriggersPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableHistoryPage;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogEntryReadActionDTO;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogReceiptReadActionDTO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Pins the section split independently of a previously opened page. */
final class HilosChangeLogSectionPagesTest extends TestCase
{
    public function testRoutePagesKeepTheAdminGateAndOnlyFeedOwnsDetailReads(): void
    {
        foreach ([
            AbstractHilosChangeLogFeedPage::class => [HilosPageConstants::HILOS_CHANGE_LOG_FEED,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_FEED],
            AbstractHilosChangeLogNoisyPage::class => [HilosPageConstants::HILOS_CHANGE_LOG_NOISY,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_NOISY],
            AbstractHilosChangeLogTableColumnsPage::class => [HilosPageConstants::HILOS_CHANGE_LOG_TABLE_COLUMNS,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_COLUMNS],
            AbstractHilosChangeLogTableTriggersPage::class => [HilosPageConstants::HILOS_CHANGE_LOG_TABLE_TRIGGERS,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_TRIGGERS],
            AbstractHilosChangeLogTableHistoryPage::class => [HilosPageConstants::HILOS_CHANGE_LOG_TABLE_HISTORY,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_HISTORY],
        ] as $class => [$page, $signal]) {
            self::assertSame($page, $class::PAGE);
            self::assertSame(PageReach::ROUTE, $class::REACH);
            self::assertSame(PageAccessLevel::ADMIN, $class::ACCESS_LEVEL);
            self::assertSame([BrowserConfigKey::SIGNAL => $signal], $class::BROWSER);
        }
        self::assertSame([], AbstractHilosChangeLogDashboardPage::ACTIONS);
        self::assertSame([], AbstractHilosChangeLogDashboardPage::READING_ACTIONS);
        self::assertSame([
            HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ => HilosChangeLogReceiptReadActionDTO::class,
            HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ => HilosChangeLogEntryReadActionDTO::class,
        ], AbstractHilosChangeLogFeedPage::ACTIONS);
        self::assertSame(array_keys(AbstractHilosChangeLogFeedPage::ACTIONS), AbstractHilosChangeLogFeedPage::READING_ACTIONS);
        self::assertFalse(is_subclass_of(AbstractHilosChangeLogFeedPage::class, AbstractHilosChangeLogDashboardPage::class));
    }

    public function testDirectFeedBuildsItsOwnOverviewAndNoisySnapshotLivesOnNoisyOnly(): void
    {
        $previous = Hilos::$db;
        $tables = $this->createMock(ChangeLogTables::class);
        $tables->expects(self::once())->method('sectionOverview')->willReturn(new ChangeLogOverview(
            12, 2048, new DateTimeImmutable('2026-10-09T10:11:12.123Z'), ['bot'], 32,
        ));
        $tables->expects(self::once())->method('sectionNoisyColumns')->willReturn(ChangeLogReadCollection::fromArray([
            new ChangeLogNoisyColumn('bot', 'heartbeat', 'Updated automatically'),
        ]));
        $db = $this->createMock(HilosDbContext::class);
        $db->method('__get')->with(HilosDbContext::changeLogTables)->willReturn($tables);
        Hilos::$db = $db;
        try {
            $feed = new ReflectionClass(SectionFeedPage::class)->newInstanceWithoutConstructor();
            $payload = new ReflectionMethod($feed, 'buildPagePayload')->invoke($feed, 'direct-feed', new PageRouteParams([]));
            self::assertSame(['changeLogOverview' => [
                'journalEntries' => 12, 'journalBytes' => 2048, 'oldestAt' => '2026-10-09T10:11:12.123Z',
                'journaledTables' => ['bot'], 'liveTables' => 32,
            ]], $payload->data);
            self::assertSame($payload->data, ViewerFields::hide($payload->data,
                new ReflectionMethod($feed, 'dataFields')->invoke($feed), static fn(): bool => false));
            $noisy = new ReflectionClass(SectionNoisyPage::class)->newInstanceWithoutConstructor();
            $payload = new ReflectionMethod($noisy, 'buildPagePayload')->invoke($noisy, 'direct-noisy', new PageRouteParams([]));
            self::assertSame(['changeLogNoisyColumns' => [
                ['table' => 'bot', 'column' => 'heartbeat', 'reason' => 'Updated automatically'],
            ]], $payload->data);
            self::assertSame($payload->data, ViewerFields::hide($payload->data,
                new ReflectionMethod($noisy, 'dataFields')->invoke($noisy), static fn(): bool => false));
            $tablePage = new ReflectionClass(SectionTablesPage::class)->newInstanceWithoutConstructor();
            self::assertNull(new ReflectionMethod($tablePage, 'buildPagePayload')
                ->invoke($tablePage, 'tables', new PageRouteParams([])));
        } finally {
            Hilos::$db = $previous;
        }
    }
}

final class SectionFeedPage extends AbstractHilosChangeLogFeedPage
{
}

final class SectionNoisyPage extends AbstractHilosChangeLogNoisyPage
{
}

final class SectionTablesPage extends AbstractHilosChangeLogTablesPage
{
}
