<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Core\Analytics\AnalyticsSectionPerson;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Tables\Analytics\HilosAnalyticsPersonTableRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The table lifecycle retains scalars; viewer marks belong to the wire sanitizer. */
final class HilosAnalyticsPersonTableRowTest extends TestCase
{
    public function testDeletedAndEmptyNamesRemainDistinctThroughTheRowLifecycle(): void
    {
        foreach ([null, '', 'Current name'] as $name) {
            $row = HilosAnalyticsPersonTableRow::fromPerson(new AnalyticsSectionPerson(10, 3, 400), $name);
            self::assertSame(10, $row->requireRowKey());
            self::assertSame('rowKey', $row::keyField());
            self::assertSame([
                'rowKey' => 10, 'name' => $name, 'sessionCount' => 3, 'lastSeenTs' => 400,
            ], HilosAnalyticsPersonTableRow::fromArray($row->toArray())->toArray());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed internal rows */
    public static function malformedRows(): iterable
    {
        $valid = ['rowKey' => 10, 'name' => 'Current name', 'sessionCount' => 3, 'lastSeenTs' => 400];
        foreach (['rowKey', 'sessionCount', 'lastSeenTs'] as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield $key . ' missing' => [$missing];
            foreach (['10', 10.0, null, true, ['_hidden' => true]] as $index => $value) {
                yield $key . ' mistyped ' . $index => [[$key => $value] + $valid];
            }
        }
        yield 'hidden name is not a scalar row' => [['name' => ['_hidden' => true]] + $valid];
    }

    /** @param array<string, mixed> $data Malformed internal row */
    #[DataProvider('malformedRows')]
    public function testMalformedLifecycleRowsRefuse(array $data): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosAnalyticsPersonTableRow::fromArray($data);
    }
}
