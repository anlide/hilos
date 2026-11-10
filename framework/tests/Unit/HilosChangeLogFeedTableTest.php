<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @param int $moment Source epoch milliseconds
     * @param string $expected Canonical browser UTC-Z text
     */
    #[DataProvider('displayMoments')]
    public function testDisplayAndAnchorKeepExactMilliseconds(int $moment, string $expected): void
    {
        self::assertSame($expected, HilosChangeLogTableParts::displayTime($moment));
        self::assertSame($moment, HilosChangeLogTableParts::anchorTime($moment));
        self::assertSame($moment, HilosChangeLogTableParts::readAnchorTime(new TableAnchorDTO([
            HilosChangeLogTableParts::ANCHOR_CREATED_AT => $moment,
        ])));
    }

    /** @return array<string, array{int, string}> Epoch, negative and future instants */
    public static function displayMoments(): array
    {
        return [
            'milliseconds' => [1791540672123, '2026-10-09T10:11:12.123Z'],
            'epoch' => [0, '1970-01-01T00:00:00.000Z'],
            'before epoch' => [-1, '1969-12-31T23:59:59.999Z'],
            'after 2038' => [2208988800456, '2040-01-01T00:00:00.456Z'],
        ];
    }

    /** @param mixed $value A non-integer anchor value */
    #[DataProvider('invalidAnchorMoments')]
    public function testNonIntegerAnchorIsRefused(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Change log anchor carries an invalid createdAt');
        HilosChangeLogTableParts::readAnchorTime(new TableAnchorDTO([
            HilosChangeLogTableParts::ANCHOR_CREATED_AT => $value,
        ]));
    }

    /** @return array<string, array{mixed}> Former SQL strings and fractional numbers */
    public static function invalidAnchorMoments(): array
    {
        return ['SQL text' => ['2026-10-09 10:11:12.123456'], 'numeric text' => ['1791540672123'], 'fraction' => [1.5]];
    }

    /** @return int A UTC epoch-ms moment with a subsecond component */
    private static function time(): int
    {
        return 1791540672123;
    }
}
