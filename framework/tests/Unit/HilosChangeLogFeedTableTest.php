<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTableRow;
use Hilos\Tables\ChangeLog\HilosChangeLogTableParts;
use Hilos\Database\ChangeLog\Section\ChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItem;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItemKind;
use Hilos\Database\ChangeLog\Section\ChangeLogPerson;
use Hilos\Database\ChangeLog\Section\ChangeLogReceipt;
use Hilos\Database\ChangeLog\Section\ChangeLogTouchedRecord;
use Hilos\Database\ChangeLog\Section\ChangeLogTouchedTable;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\User as ObjectUser;
use PHPUnit\Framework\TestCase;

/** The feed keeps receipt and bare-entry identities distinct on the browser wire. */
final class HilosChangeLogFeedTableTest extends TestCase
{
    public function testReceiptSingleAndMultiRecordSummariesRoundTrip(): void
    {
        $time = self::time();
        $receipt = new ChangeLogReceipt(
            7, $time, new ChangeLogPerson(3, 'Ada', false), new ChangeLogPerson(4, 'Deleted user #4', true),
            null, 'web', 'bot.update', null, null, 3,
            [
                new ChangeLogTouchedTable('bot', 1, new ChangeLogTouchedRecord([3], 'update', 2)),
                new ChangeLogTouchedTable('hilos_user', 2, null),
            ],
        );
        $row = HilosChangeLogFeedTableRow::fromItem(new ChangeLogFeedItem(ChangeLogFeedItemKind::RECEIPT, $time, $receipt, null));
        self::assertSame('receipt:7', $row->getRowKey());
        self::assertSame('2026-10-09T10:11:12.123Z', $row->createdAt);
        self::assertSame('Deleted user #4', $row->subjectLabel);
        self::assertTrue($row->subjectDeleted);
        self::assertSame([
            ['table' => 'bot', 'records' => 1, 'recordKey' => [3], 'mutation' => 'update', 'changedFields' => 2],
            ['table' => 'hilos_user', 'records' => 2, 'recordKey' => null, 'mutation' => null, 'changedFields' => null],
        ], $row->touched);
        self::assertArrayNotHasKey('id', $row->toArray());
        self::assertSame($row->toArray(), HilosChangeLogFeedTableRow::fromArray($row->toArray())->toArray());
        $table = new HilosChangeLogFeedTable();
        self::assertSame(array_keys($row->toArray()), array_keys($table->wireFields()));
        self::assertSame(HilosDbContext::users, $table->wireFields()[HilosChangeLogFeedTableRow::actorLabel]->collection);
        self::assertSame(ObjectUser::name, $table->wireFields()[HilosChangeLogFeedTableRow::subjectLabel]->field);
        self::assertInstanceOf(WireField::class, $table->wireFields()[HilosChangeLogFeedTableRow::touched]);
    }

    public function testBareEntryHasItsOwnRowKeyAndOneTouchedRecord(): void
    {
        $entry = new ChangeLogEntry(7, self::time(), null, 'bot', [3], 'delete', []);
        $row = HilosChangeLogFeedTableRow::fromItem(new ChangeLogFeedItem(ChangeLogFeedItemKind::ENTRY, self::time(), null, $entry));
        self::assertSame('entry:7', $row->rowKey);
        self::assertNull($row->receiptId);
        self::assertSame(7, $row->entryId);
        self::assertNull($row->actorLabel);
        self::assertSame([
            ['table' => 'bot', 'records' => 1, 'recordKey' => [3], 'mutation' => 'delete', 'changedFields' => 0],
        ], $row->touched);
    }

    public function testMalformedTouchedItemIsRefused(): void
    {
        $entry = new ChangeLogEntry(7, self::time(), null, 'bot', [3], 'delete', []);
        $data = HilosChangeLogFeedTableRow::fromItem(
            new ChangeLogFeedItem(ChangeLogFeedItemKind::ENTRY, self::time(), null, $entry),
        )->toArray();
        $data[HilosChangeLogFeedTableRow::touched] = ['broken'];
        $this->expectException(InvalidFormatException::class);
        HilosChangeLogFeedTableRow::fromArray($data);
    }

    public function testNumberedWindowChoosesNearerEdgeAndStopsAtCeiling(): void
    {
        self::assertSame(['direction' => TableAnchorDirection::After, 'skip' => 0, 'take' => 25],
            HilosChangeLogTableParts::numberedWindow(0, 25, 100, true));
        self::assertSame(['direction' => TableAnchorDirection::After, 'skip' => 25, 'take' => 25],
            HilosChangeLogTableParts::numberedWindow(1, 25, 100, true));
        self::assertSame(['direction' => TableAnchorDirection::Before, 'skip' => 0, 'take' => 25],
            HilosChangeLogTableParts::numberedWindow(3, 25, 100, true));
        self::assertNull(HilosChangeLogTableParts::numberedWindow(4, 25, 100, true));
        self::assertSame(['direction' => TableAnchorDirection::After, 'skip' => 25, 'take' => 25],
            HilosChangeLogTableParts::numberedWindow(1, 25, 500, false));
        self::assertNull(HilosChangeLogTableParts::numberedWindow(21, 25, 500, false));
    }

    public function testViewerSearchAllowsNumbersButNotHiddenNamesWithoutSql(): void
    {
        $table = new HilosChangeLogFeedTable();
        $shown = [HilosChangeLogFeedTableRow::actorId, HilosChangeLogFeedTableRow::subjectId];
        $refused = $table->getPage(new TableQueryDTO(search: 'Ada', shownFields: $shown));
        self::assertSame([], $refused->rows);
        self::assertSame(0, $refused->totalCount);
        $scoped = $table->scopeSearch(new TableQueryDTO(search: '#3', shownFields: $shown));
        self::assertSame('#3', HilosChangeLogTableParts::who($scoped));
    }

    public function testIncompleteAnchorIsRefusedBeforeSql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HilosChangeLogTableParts::readAnchorTime(new TableAnchorDTO(['createdAt' => '2026-10-09']));
    }

    public function testInvalidPeriodIsRefusedEvenWhenViewerSearchIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HilosChangeLogFeedTable()->getPage(new TableQueryDTO(
            search: 'Ada',
            shownFields: [HilosChangeLogFeedTableRow::actorId, HilosChangeLogFeedTableRow::subjectId],
            filter: [HilosChangeLogFeedTable::FILTER_PERIOD => 'year'],
        ));
    }

    /** @return DateTimeImmutable A UTC moment with a subsecond component */
    private static function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09 10:11:12.123456', new DateTimeZone('UTC'));
    }
}
