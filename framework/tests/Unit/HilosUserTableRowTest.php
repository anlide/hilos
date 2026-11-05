<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Tables\Users\HilosUserTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the row of the Hilos users table, one shape for every project.
 */
final class HilosUserTableRowTest extends TestCase
{
    public function testGetRowKeyIsId(): void
    {
        $this->assertSame(42, (new HilosUserTableRow(42))->getRowKey());
    }

    /**
     * The order of the keys is part of the wire, and the tables' own assertions compare it exactly.
     */
    public function testToArrayCarriesTheNineFieldsInTheWireOrder(): void
    {
        $row = new HilosUserTableRow(
            id: 42,
            admin: true,
            block: false,
            name: 'Ann',
            lastActivity: 1790762400000,
            onlineSessionCount: 3,
            presence: 'online',
            merged: true,
            openSessionCount: 1,
        );

        $this->assertSame(
            [
                HilosUserTableRow::id => 42,
                HilosUserTableRow::admin => true,
                HilosUserTableRow::block => false,
                HilosUserTableRow::onlineSessionCount => 3,
                HilosUserTableRow::openSessionCount => 1,
                HilosUserTableRow::presence => 'online',
                HilosUserTableRow::name => 'Ann',
                HilosUserTableRow::lastActivity => 1790762400000,
                HilosUserTableRow::merged => true,
            ],
            $row->toArray(),
        );
    }

    public function testARowIsNotMergedUnlessTheTableSaysSo(): void
    {
        $this->assertFalse((new HilosUserTableRow(42))->merged);
        $this->assertFalse((new HilosUserTableRow(42))->toArray()[HilosUserTableRow::merged]);
    }

    public function testARowSurvivesTheRoundTripThroughItsPayload(): void
    {
        $row = new HilosUserTableRow(
            id: 7,
            admin: false,
            block: true,
            name: 'Bob',
            lastActivity: null,
            onlineSessionCount: 3,
            presence: null,
            openSessionCount: null,
        );

        $this->assertArrayHasKey(HilosUserTableRow::openSessionCount, $row->toArray());
        $this->assertNull($row->toArray()[HilosUserTableRow::openSessionCount]);
        $this->assertSame($row->toArray(), HilosUserTableRow::fromArray($row->toArray())->toArray());
    }

    public function testDistinctSessionCountSurvivesTheRoundTrip(): void
    {
        $row = new HilosUserTableRow(7, onlineSessionCount: 3, openSessionCount: 1);

        $restored = HilosUserTableRow::fromArray($row->toArray());
        $this->assertSame(1, $restored->openSessionCount);
        $this->assertSame(3, $restored->onlineSessionCount);
        $this->assertSame($row->toArray(), $restored->toArray());
    }

    public function testAbsentSessionCountRemainsUnknown(): void
    {
        $payload = (new HilosUserTableRow(7, onlineSessionCount: 3))->toArray();
        unset($payload[HilosUserTableRow::openSessionCount]);

        $this->assertNull(HilosUserTableRow::fromArray($payload)->openSessionCount);
    }

    public function testSessionCountOfAnotherTypeIsRefused(): void
    {
        $payload = (new HilosUserTableRow(7))->toArray();
        $payload[HilosUserTableRow::openSessionCount] = '1';

        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HilosUserTableRow::openSessionCount);

        HilosUserTableRow::fromArray($payload);
    }

    public function testNumericActivitySurvivesTheRoundTripIncludingEpochZero(): void
    {
        foreach ([0, 1790762400000] as $moment) {
            $row = new HilosUserTableRow(7, lastActivity: $moment);

            $this->assertSame($row->toArray(), HilosUserTableRow::fromArray($row->toArray())->toArray());
        }
    }

    public function testPresenceFieldNamesMatchTheSummaryDto(): void
    {
        $this->assertSame(HilosUserPresenceSummary::presence, HilosUserTableRow::presence);
        $this->assertSame(HilosUserPresenceSummary::onlineSessionCount, HilosUserTableRow::onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::openSessionCount, HilosUserTableRow::openSessionCount);
    }

    public function testARowPayloadWithoutTheUserIdIsRefused(): void
    {
        // A row payload is the table's own toArray() output, so a missing id is a
        // row that lost it; read as zero it would address user 0 in the browser
        // window and in every action fired from that line.
        $payload = (new HilosUserTableRow(42, name: 'Ann'))->toArray();
        unset($payload[HilosUserTableRow::id]);

        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HilosUserTableRow::id);

        HilosUserTableRow::fromArray($payload);
    }

    public function testARowPayloadWithoutTheNameIsRefused(): void
    {
        $payload = (new HilosUserTableRow(42, name: 'Ann'))->toArray();
        unset($payload[HilosUserTableRow::name]);

        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HilosUserTableRow::name);

        HilosUserTableRow::fromArray($payload);
    }
}
