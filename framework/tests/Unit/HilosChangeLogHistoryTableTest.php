<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\ChangeLog\Section\ChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogFieldChange;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryRow;
use Hilos\Database\ChangeLog\Section\ChangeLogPerson;
use Hilos\Database\ChangeLog\Section\ChangeLogReceiptHeader;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTableRow;
use Hilos\Tables\ChangeLog\HilosChangeLogPeriod;
use Hilos\Tables\ChangeLog\HilosChangeLogTableParts;
use PHPUnit\Framework\TestCase;

/** History rows keep field presence and omit long bodies on the browser wire. */
final class HilosChangeLogHistoryTableTest extends TestCase
{
    public function testHistoryRowRoundTripsItsChangesAndAttribution(): void
    {
        $time = new DateTimeImmutable('2026-10-09 10:11:12.123456', new DateTimeZone('UTC'));
        $entry = new ChangeLogEntry(9, $time, 7, 'bot', [3], 'update', [
            new ChangeLogFieldChange('name', 'inline', true, true, 'old', 'new', false),
            new ChangeLogFieldChange('body', 'long', true, true, null, null, true),
        ]);
        $header = new ChangeLogReceiptHeader(
            7, $time, new ChangeLogPerson(4, 'Deleted user #4', true), null, null, 'web', 'bot.update', null, null,
        );
        $row = HilosChangeLogHistoryTableRow::fromItem(new ChangeLogHistoryRow($entry, $header));
        self::assertSame(9, $row->getRowKey());
        self::assertSame('2026-10-09T10:11:12.123Z', $row->createdAt);
        self::assertTrue($row->actorDeleted);
        self::assertSame('long', $row->changes[1]['kind']);
        self::assertTrue($row->changes[1]['bodyOmitted']);
        self::assertArrayNotHasKey('id', $row->toArray());
        self::assertSame($row->toArray(), HilosChangeLogHistoryTableRow::fromArray($row->toArray())->toArray());
        self::assertSame(array_keys($row->toArray()), array_keys(new HilosChangeLogHistoryTable()->wireFields()));
        self::assertSame(TableConstants::ORDER_DESC, new HilosChangeLogHistoryTable()->defaultSort()->last()->direction);
    }

    public function testMalformedChangeItemIsRefused(): void
    {
        $time = new DateTimeImmutable('2026-10-09', new DateTimeZone('UTC'));
        $row = HilosChangeLogHistoryTableRow::fromItem(new ChangeLogHistoryRow(
            new ChangeLogEntry(9, $time, null, 'bot', [3], 'delete', []), null,
        ));
        $data = $row->toArray();
        $data[HilosChangeLogHistoryTableRow::changes] = [3];
        $this->expectException(InvalidFormatException::class);
        HilosChangeLogHistoryTableRow::fromArray($data);
    }

    public function testMissingTableIsAnEmptyWindowWithoutSql(): void
    {
        $page = new HilosChangeLogHistoryTable()->getPage(new TableQueryDTO());
        self::assertSame([], $page->rows);
        self::assertSame(0, $page->totalCount);
        self::assertSame(0, $page->rowsBefore);
    }

    public function testInvalidPeriodIsRefusedEvenWithoutTable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HilosChangeLogHistoryTable()->getPage(new TableQueryDTO(filter: [
            HilosChangeLogHistoryTable::FILTER_PERIOD => 'year',
        ]));
    }

    public function testBadRecordFilterIsRefusedBeforeSql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HilosChangeLogHistoryTable()->getPage(new TableQueryDTO(filter: [
            HilosChangeLogHistoryTable::FILTER_TABLE => 'bot',
            HilosChangeLogHistoryTable::FILTER_RECORD => ['id' => 3],
        ]));
    }

    public function testPeriodBoundsAndUnknownValue(): void
    {
        $now = new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('UTC'));
        self::assertSame('2026-10-09 11:00:00', HilosChangeLogPeriod::HOUR->since($now)->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-09 12:00:00', HilosChangeLogPeriod::MONTH->since($now)->format('Y-m-d H:i:s'));
        $this->expectException(InvalidArgumentException::class);
        HilosChangeLogTableParts::since('year');
    }
}
