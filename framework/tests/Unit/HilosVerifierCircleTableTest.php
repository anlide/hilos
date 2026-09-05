<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the verifier circle table's row shape and serialization (HIL-643).
 *
 * What is pinned here is the wire: the membership key the removal names, the pair the freeze
 * resolves back to a person, and the online mark - which is a field of the row rather than of the
 * database, and would otherwise be the easiest of the four to drop on the way out.
 */
final class HilosVerifierCircleTableTest extends TestCase
{
    public function testBrowserRowDropsTheIdAndWrapsTheSlotUnderTheCircleSource(): void
    {
        $row = new HilosVerifierCircleTableRow(
            id: 7,
            identityType: 'password',
            identifier: 'ann@example.test',
            online: true,
        );

        $browserRow = new HilosVerifierCircleTable()->browserRow($row);

        // The membership id, not the address: a rename must not move the row the removal names.
        $this->assertSame(7, $browserRow[BrowserPageSignalData::rowKey]);

        $slot = $browserRow[BrowserPageSignalData::sources][HilosDbContext::verifierCircle];
        // A slot carrying a non-null id is read as an entity fragment and replaced by a
        // reference, which would leave the view resolving fields off a reference to a
        // collection nothing owns. Nothing is lost: the id IS the row key above.
        $this->assertArrayNotHasKey(HilosVerifierCircleTableRow::id, $slot);
        $this->assertSame('password', $slot[HilosVerifierCircleTableRow::identityType]);
        $this->assertSame('ann@example.test', $slot[HilosVerifierCircleTableRow::identifier]);
        // The computed field rides the same slot as the stored three: it is a field of the row.
        $this->assertTrue($slot[HilosVerifierCircleTableRow::online]);
    }

    public function testARowRoundTripsThroughItsPayload(): void
    {
        $row = new HilosVerifierCircleTableRow(
            id: 12,
            identityType: 'sms',
            identifier: '+10000000001',
            online: false,
        );

        $restored = HilosVerifierCircleTableRow::fromArray($row->toArray());

        $this->assertSame(12, $restored->getRowKey());
        $this->assertSame('sms', $restored->identityType);
        $this->assertSame('+10000000001', $restored->identifier);
        $this->assertFalse($restored->online, 'Somebody with no tab open is named and not here');
    }

    public function testTheRowKeyFieldIsTheMembershipId(): void
    {
        // The tie-breaker the in-memory window sorts by, and the field a removal payload names.
        $this->assertSame(HilosVerifierCircleTableRow::id, HilosVerifierCircleTableRow::keyField());
    }
}
