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
    public function testToArrayCarriesTheSevenFieldsInTheWireOrder(): void
    {
        $row = new HilosUserTableRow(
            id: 42,
            admin: true,
            block: false,
            name: 'Ann',
            lastActivity: '2026-09-30 10:00:00',
            onlineSessionCount: 3,
            presence: 'online',
        );

        $this->assertSame(
            [
                HilosUserTableRow::id => 42,
                HilosUserTableRow::admin => true,
                HilosUserTableRow::block => false,
                HilosUserTableRow::onlineSessionCount => 3,
                HilosUserTableRow::presence => 'online',
                HilosUserTableRow::name => 'Ann',
                HilosUserTableRow::lastActivity => '2026-09-30 10:00:00',
            ],
            $row->toArray(),
        );
    }

    public function testARowSurvivesTheRoundTripThroughItsPayload(): void
    {
        $row = new HilosUserTableRow(
            id: 7,
            admin: false,
            block: true,
            name: 'Bob',
            lastActivity: null,
            onlineSessionCount: 0,
            presence: null,
        );

        $this->assertSame($row->toArray(), HilosUserTableRow::fromArray($row->toArray())->toArray());
    }

    public function testPresenceFieldNamesMatchTheSummaryDto(): void
    {
        $this->assertSame(HilosUserPresenceSummary::presence, HilosUserTableRow::presence);
        $this->assertSame(HilosUserPresenceSummary::onlineSessionCount, HilosUserTableRow::onlineSessionCount);
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
