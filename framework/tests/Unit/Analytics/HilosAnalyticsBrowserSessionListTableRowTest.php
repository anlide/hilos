<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Core\Analytics\AnalyticsSectionBrowserSession;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTableRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Scalar rows keep nullable identity distinct from labels and expose one envelope address. */
final class HilosAnalyticsBrowserSessionListTableRowTest extends TestCase
{
    public function testTypedRowsKeepGuestsDeletedAccountsAndEmptyNamesDistinct(): void
    {
        foreach ([[null, null], [7, null], [7, ''], [7, 'Deleted user #8'], [7, 'Current name']] as [$id, $name]) {
            $session = new AnalyticsSectionBrowserSession(10, 100, 400, null, $id, 'DTO label is not a raw name', 3);
            $row = HilosAnalyticsBrowserSessionListTableRow::fromSession($session, $name);
            self::assertSame(10, $row->requireRowKey());
            self::assertSame('rowKey', $row::keyField());
            $values = [
                'rowKey' => 10, 'lastSignedInUserId' => $id, 'lastSignedInUserName' => $name, 'browser' => null,
                'firstSeenTs' => 100, 'lastSeenTs' => 400, 'actionCount' => 3,
            ];
            self::assertSame($values, $row->toArray());
            self::assertSame($values, HilosAnalyticsBrowserSessionListTableRow::fromArray($values)->toArray());
            unset($values['rowKey']);
            self::assertSame(['rowKey' => 10, 'sources' => ['browserSession' => $values]],
                new HilosAnalyticsBrowserSessionListTable()->browserRow($row));
        }
        $row = new HilosAnalyticsBrowserSessionListTableRow(11, 7, 'Current name', 'Edge 129 · Windows', 200, 500, 0);
        self::assertSame($row->toArray(), HilosAnalyticsBrowserSessionListTableRow::fromArray($row->toArray())->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Mistyped internal rows */
    public static function malformedRows(): iterable
    {
        $valid = [
            'rowKey' => 10, 'lastSignedInUserId' => 7, 'lastSignedInUserName' => 'Current name', 'browser' => null,
            'firstSeenTs' => 100, 'lastSeenTs' => 400, 'actionCount' => 3,
        ];
        foreach (['rowKey', 'firstSeenTs', 'lastSeenTs', 'actionCount'] as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield $key . ' missing' => [$missing];
            foreach (['10', 10.0, null, true, ['_hidden' => true]] as $index => $value) {
                yield $key . ' mistyped ' . $index => [[$key => $value] + $valid];
            }
        }
        foreach (['10', 10.0, true, ['_hidden' => true]] as $index => $value) {
            yield 'identity mistyped ' . $index => [['lastSignedInUserId' => $value] + $valid];
        }
        foreach (['lastSignedInUserName', 'browser'] as $key) {
            foreach ([10, true, ['_hidden' => true]] as $index => $value) {
                yield $key . ' mistyped ' . $index => [[$key => $value] + $valid];
            }
        }
    }

    /** @param array<string, mixed> $data Mistyped internal row */
    #[DataProvider('malformedRows')]
    public function testMalformedRowsRefuse(array $data): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosAnalyticsBrowserSessionListTableRow::fromArray($data);
    }
}
