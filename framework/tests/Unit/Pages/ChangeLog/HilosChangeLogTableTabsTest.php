<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\ChangeLog;

use Hilos\AdminViewMode\ViewerFields;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\ChangeLog\Section\ChangeLogColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogTableDetail;
use Hilos\Database\ChangeLog\Section\ChangeLogTableOwner;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Database\ChangeLog\Section\ChangeLogTrigger;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\View\Collection\ChangeLogTables;
use Hilos\Hilos;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableContextPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablePage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableColumnsPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableTriggersPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableHistoryPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Direct-entry snapshots, applicability and refusals share one live-table contract. */
final class HilosChangeLogTableTabsTest extends TestCase
{
    /** @return iterable<string, array{class-string<AbstractHilosChangeLogTableContextPage>, bool}> Each route's journal requirement */
    public static function pages(): iterable
    {
        yield 'overview' => [TableOverviewFixturePage::class, false];
        yield 'columns' => [TableColumnsFixturePage::class, true];
        yield 'triggers' => [TableTriggersFixturePage::class, false];
        yield 'history' => [TableHistoryFixturePage::class, true];
    }

    /**
     * @param class-string<AbstractHilosChangeLogTableContextPage> $class Directly subscribed page
     * @param bool $requiresJournal Whether untracked tables are inapplicable
     */
    #[DataProvider('pages')]
    public function testFirstAnswerCarriesTheWholeUnchangedSectionAndViewerMetadata(string $class, bool $requiresJournal): void
    {
        $this->withTable(self::detail(true), function () use ($class): void {
            $page = new ReflectionClass($class)->newInstanceWithoutConstructor();
            $payload = new ReflectionMethod($page, 'buildPagePayload')->invoke($page, 'direct', self::params());
            self::assertSame(['changeLogTable' => [
                'name' => 'sample', 'owner' => 'project', 'journaled' => true,
                'changesLast24h' => 0, 'lastChangeAt' => null,
                'columns' => [
                    ['name' => 'name', 'sqlType' => 'varchar', 'mode' => 'personal', 'storage' => 'fact', 'noiseReason' => null],
                ],
                'triggers' => [
                    ['name' => 'service_insert', 'event' => 'INSERT', 'table' => 'sample', 'validFromMigration' => 7],
                ],
            ]], $payload->data);
            self::assertSame($payload->data, ViewerFields::hide($payload->data,
                new ReflectionMethod($page, 'dataFields')->invoke($page), static fn(): bool => false));
            self::assertSame([], $payload->windows);
            self::assertSame([], $class::ACTIONS);
            self::assertSame([], $class::READING_ACTIONS);
            self::assertSame(PageAccessLevel::ADMIN, $class::ACCESS_LEVEL);
            self::assertSame(PageReach::ROUTE, $class::REACH);
            self::assertTrue(new ReflectionMethod($class, 'onSubscribeBeforeResponse')->isFinal());
            self::assertTrue(new ReflectionMethod($class, 'onUpdateSubscription')->isFinal());
            self::assertSame(AbstractHilosChangeLogTableContextPage::class,
                new ReflectionMethod($class, 'onUpdateSubscription')->getDeclaringClass()->getName());
        });
        self::assertSame($requiresJournal, in_array($class, [TableColumnsFixturePage::class, TableHistoryFixturePage::class], true));
    }

    /**
     * @param class-string<AbstractHilosChangeLogTableContextPage> $class Directly subscribed page
     * @param bool $requiresJournal Whether untracked tables are refused
     */
    #[DataProvider('pages')]
    public function testUntrackedTableApplicabilityMatchesDirectEntry(string $class, bool $requiresJournal): void
    {
        $this->withTable(self::detail(false), function () use ($class, $requiresJournal): void {
            self::assertSame(!$requiresJournal, $class::isTabApplicable(self::params()));
            $page = new ReflectionClass($class)->newInstanceWithoutConstructor();
            if ($requiresJournal) {
                $this->expectException(PageResourceNotFoundException::class);
            }
            $payload = new ReflectionMethod($page, 'buildPagePayload')->invoke($page, 'direct', self::params());
            self::assertFalse($payload->data['changeLogTable']['journaled']);
            self::assertSame([], $payload->data['changeLogTable']['triggers']);
        });
    }

    /**
     * @param class-string<AbstractHilosChangeLogTableContextPage> $class Directly subscribed page
     * @param bool $requiresJournal Unused journal requirement
     */
    #[DataProvider('pages')]
    public function testUnknownTableIsRefused(string $class, bool $requiresJournal): void
    {
        $this->withTable(null, function () use ($class): void {
            $page = new ReflectionClass($class)->newInstanceWithoutConstructor();
            $this->expectException(PageResourceNotFoundException::class);
            new ReflectionMethod($page, 'buildPagePayload')->invoke($page, 'direct', self::params());
        });
    }

    /**
     * @param class-string<AbstractHilosChangeLogTableContextPage> $class Directly subscribed page
     * @param bool $requiresJournal Unused journal requirement
     */
    #[DataProvider('pages')]
    public function testMissingTableIsRefusedBeforeARead(string $class, bool $requiresJournal): void
    {
        $page = new ReflectionClass($class)->newInstanceWithoutConstructor();
        $this->expectException(MissingPageRouteParamException::class);
        new ReflectionMethod($page, 'onSubscribeBeforeResponse')->invoke($page, 'direct', new PageRouteParams([]));
    }

    public function testConditionalTabsRejectAbsentEmptyAndUnknownTableNames(): void
    {
        foreach ([TableColumnsFixturePage::class, TableHistoryFixturePage::class] as $class) {
            self::assertFalse($class::isTabApplicable(new PageRouteParams([])));
            self::assertFalse($class::isTabApplicable(new PageRouteParams(['tableId' => ''])));
            $this->withTable(null, static function () use ($class): void {
                self::assertFalse($class::isTabApplicable(self::params()));
            });
            $this->withTable(self::detail(true), static function () use ($class): void {
                self::assertTrue($class::isTabApplicable(self::params()));
            });
        }
    }

    /** @return PageRouteParams Live table address */
    private static function params(): PageRouteParams
    {
        return new PageRouteParams([HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID => 'sample']);
    }

    /**
     * @param bool $journaled Whether the fixture has a journal label
     * @return ChangeLogTableDetail Immutable metadata source
     */
    private static function detail(bool $journaled): ChangeLogTableDetail
    {
        return new ChangeLogTableDetail(
            new ChangeLogTableSummary('sample', ChangeLogTableOwner::PROJECT, $journaled, [], $journaled ? 0 : null, null),
            [new ChangeLogColumn('name', 'varchar', $journaled ? JournalColumnMode::PERSONAL : null, $journaled ? 'fact' : null, null)],
            $journaled ? [new ChangeLogTrigger('service_insert', 'INSERT', 'sample', 7)] : [],
        );
    }

    /**
     * @param ?ChangeLogTableDetail $detail Request-local source result
     * @param callable(): void $read Assertions using the real page implementation
     */
    private function withTable(?ChangeLogTableDetail $detail, callable $read): void
    {
        $previous = Hilos::$db;
        $tables = $this->createMock(ChangeLogTables::class);
        $tables->method('sectionTable')->with('sample')->willReturn($detail);
        $db = $this->createMock(HilosDbContext::class);
        $db->method('__get')->with(HilosDbContext::changeLogTables)->willReturn($tables);
        Hilos::$db = $db;
        try {
            $read();
        } finally {
            Hilos::$db = $previous;
        }
    }
}

final class TableOverviewFixturePage extends AbstractHilosChangeLogTablePage
{
}

final class TableColumnsFixturePage extends AbstractHilosChangeLogTableColumnsPage
{
}

final class TableTriggersFixturePage extends AbstractHilosChangeLogTableTriggersPage
{
}

final class TableHistoryFixturePage extends AbstractHilosChangeLogTableHistoryPage
{
}
