<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupShipState;
use Hilos\Backup\BackupStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the standing copy state one list row shows.
 *
 * The state is derived, never stored: what the sidecar keeps is the outcome of the last attempt,
 * and the column is read off it plus the one fact the sidecar cannot know - whether this
 * installation ships anywhere at all.
 */
final class BackupShipStateTest extends TestCase
{
    /** Status of a run that published an archive - the only kind of row a copy is owed for. */
    private const string SUCCESS = BackupStatus::SUCCESS->value;

    public function testNoDestinationReadsAsNoneWhateverTheRecordSays(): void
    {
        // Shipping was configured once and rows recorded it; the destination is gone now, and a
        // backup must not read as pending for a copy that is never coming.
        $this->assertSame(
            BackupShipState::NONE,
            BackupShipState::fromRecord(false, self::SUCCESS, '2026-08-16T10:00:00+00:00', 'ok'),
        );
        $this->assertSame(BackupShipState::NONE, BackupShipState::fromRecord(false, self::SUCCESS, null, 'failed'));
        $this->assertSame(BackupShipState::NONE, BackupShipState::fromRecord(false, self::SUCCESS, null, null));
    }

    public function testAConfiguredDestinationAndNoAttemptReadsAsPending(): void
    {
        $this->assertSame(BackupShipState::PENDING, BackupShipState::fromRecord(true, self::SUCCESS, null, null));
    }

    public function testAFailedRunReadsAsNoneEvenWithADestination(): void
    {
        // A run that ended in error published no archive, so BackupShipPlanner will never pick the
        // row up: "pending" would promise a transfer that is queued nowhere and never arrives.
        $error = BackupStatus::ERROR->value;

        $this->assertSame(BackupShipState::NONE, BackupShipState::fromRecord(true, $error, null, null));
        $this->assertSame(BackupShipState::NONE, BackupShipState::fromRecord(true, null, null, null));
        $this->assertSame(BackupShipState::NONE, BackupShipState::fromRecord(true, 'half-way', null, null));
    }

    public function testARecordedSuccessReadsAsShipped(): void
    {
        $this->assertSame(
            BackupShipState::SHIPPED,
            BackupShipState::fromRecord(true, self::SUCCESS, '2026-08-16T10:00:00+00:00', BackupShipOutcome::OK->value),
        );
    }

    public function testARecordedFailureReadsAsFailed(): void
    {
        $this->assertSame(
            BackupShipState::FAILED,
            BackupShipState::fromRecord(true, self::SUCCESS, null, BackupShipOutcome::FAILED->value),
        );

        // A backup shipped once and failed since keeps the instant of the older success; the
        // failure is still what the column has to say.
        $this->assertSame(
            BackupShipState::FAILED,
            BackupShipState::fromRecord(true, self::SUCCESS, '2026-08-15T10:00:00+00:00', BackupShipOutcome::FAILED->value),
        );
    }

    public function testASuccessWithoutItsInstantDegradesToPending(): void
    {
        // The pair is written in one step, so an outcome without its timestamp is a half-written
        // record: "not there yet" is the safe reading, "shipped" would be a claim nothing backs.
        $this->assertSame(BackupShipState::PENDING, BackupShipState::fromRecord(true, self::SUCCESS, null, 'ok'));
    }

    public function testAnUnknownStoredOutcomeReadsAsPending(): void
    {
        $this->assertSame(BackupShipState::PENDING, BackupShipState::fromRecord(true, self::SUCCESS, null, 'half-way'));
        $this->assertSame(BackupShipState::PENDING, BackupShipState::fromRecord(true, self::SUCCESS, null, ''));
    }

    public function testOutcomeParsingToleratesUnknownAndEmptyInput(): void
    {
        $this->assertSame(BackupShipOutcome::OK, BackupShipOutcome::fromString('ok'));
        $this->assertSame(BackupShipOutcome::FAILED, BackupShipOutcome::fromString('failed'));
        $this->assertNull(BackupShipOutcome::fromString('shipped'));
        $this->assertNull(BackupShipOutcome::fromString(''));
        $this->assertNull(BackupShipOutcome::fromString(null));
    }

    public function testStateParsingToleratesUnknownAndEmptyInput(): void
    {
        $this->assertSame(BackupShipState::SHIPPED, BackupShipState::fromString('shipped'));
        $this->assertNull(BackupShipState::fromString('sent'));
        $this->assertNull(BackupShipState::fromString(''));
        $this->assertNull(BackupShipState::fromString(null));
    }
}
