<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreProgressSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\Backup\DTO\BackupCreateActionDTO;
use Hilos\Pages\Backup\DTO\BackupDeleteActionDTO;
use Hilos\Pages\Backup\DTO\BackupRestoreActionDTO;
use Hilos\Pages\Backup\DTO\BackupSetKeepActionDTO;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Item\RestoreRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the backup list-page action DTOs (HIL-333, restore HIL-276) and the signals the
 * page hands the agent.
 *
 * Each action DTO parses from the raw WebSocket envelope — tolerating the optional FIELD_DATA
 * wrapper — and round-trips its typed fields. The create signal additionally carries who asked,
 * which is what lets the agent address the run's outcome back to that one connection; the restore
 * signal carries the same, plus the verdict and scope the page resolved and the user id whose
 * identities the agent photographs before the swap (HIL-279). The restore progress
 * frame is pinned against the runtime row it photographs, because the initiator's view and the
 * CLI monitor's are meant to be the same snapshot.
 */
final class BackupActionDtoTest extends TestCase
{
    public function testCreateReadsScopeFromABarePayload(): void
    {
        $dto = BackupCreateActionDTO::fromArray([BackupCreateActionDTO::scope => 'schema-seed']);

        $this->assertSame('schema-seed', $dto->scope);
    }

    public function testCreateUnwrapsTheFieldDataEnvelope(): void
    {
        $dto = BackupCreateActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [BackupCreateActionDTO::scope => 'full'],
        ]);

        $this->assertSame('full', $dto->scope);
    }

    public function testCreateTrimsTheScope(): void
    {
        $this->assertSame('full', BackupCreateActionDTO::fromArray([BackupCreateActionDTO::scope => '  full  '])->scope);
    }

    public function testCreateRefusesAPayloadThatNamesNoScope(): void
    {
        $this->expectException(InvalidFormatException::class);

        BackupCreateActionDTO::fromArray([]);
    }

    public function testDeleteRoundTripsTheBackupId(): void
    {
        $dto = BackupDeleteActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [BackupDeleteActionDTO::backupId => 'bk-1'],
        ]);

        $this->assertSame('bk-1', $dto->backupId);
        $this->assertSame([BackupDeleteActionDTO::backupId => 'bk-1'], $dto->toArray());
    }

    public function testSetKeepReadsIdAndPin(): void
    {
        $dto = BackupSetKeepActionDTO::fromArray([
            BackupSetKeepActionDTO::backupId => 'bk-2',
            BackupSetKeepActionDTO::keep => true,
        ]);

        $this->assertSame('bk-2', $dto->backupId);
        $this->assertTrue($dto->keep);
    }

    public function testSetKeepDefaultsAMissingPinToFalse(): void
    {
        $dto = BackupSetKeepActionDTO::fromArray([BackupSetKeepActionDTO::backupId => 'bk-3']);

        $this->assertFalse($dto->keep);
        $this->assertSame(
            [BackupSetKeepActionDTO::backupId => 'bk-3', BackupSetKeepActionDTO::keep => false],
            $dto->toArray(),
        );
    }

    public function testCreateSignalRoundTripsTheRequestingConnection(): void
    {
        $dto = BackupCreateSignalData::fromArray(
            new BackupCreateSignalData('full', 'accept-key-1')->toArray(),
        );

        $this->assertSame('full', $dto->scope);
        $this->assertSame('accept-key-1', $dto->initiatorAcceptKey);
    }

    public function testCreateSignalHasNoInitiatorWhenUnattended(): void
    {
        $dto = BackupCreateSignalData::fromArray([BackupCreateSignalData::scope => 'full']);

        $this->assertNull($dto->initiatorAcceptKey);
    }

    public function testCreateSignalRoundTripsAnUnattendedRunAsNoInitiator(): void
    {
        $dto = BackupCreateSignalData::fromArray(new BackupCreateSignalData('full')->toArray());

        $this->assertNull($dto->initiatorAcceptKey);
    }

    public function testCreateSignalRefusesAPayloadNamingNoScope(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(BackupCreateSignalData::scope);

        BackupCreateSignalData::fromArray([
            BackupCreateSignalData::initiatorAcceptKey => 'accept-key-1',
        ]);
    }

    public function testSetKeepSignalRefusesAPayloadCarryingNoPin(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(BackupSetKeepSignalData::keep);

        BackupSetKeepSignalData::fromArray([BackupSetKeepSignalData::backupId => 'bk-2']);
    }

    public function testDeleteSignalRoundTripsTheRequestingConnection(): void
    {
        $dto = BackupDeleteSignalData::fromArray(
            new BackupDeleteSignalData('bk-1', 'accept-key-1')->toArray(),
        );

        $this->assertSame('bk-1', $dto->backupId);
        $this->assertSame('accept-key-1', $dto->initiatorAcceptKey);
    }

    public function testDeleteSignalHasNoInitiatorWhenAbsent(): void
    {
        $dto = BackupDeleteSignalData::fromArray([BackupDeleteSignalData::backupId => 'bk-1']);

        $this->assertNull($dto->initiatorAcceptKey);
    }

    public function testSetKeepSignalRoundTripsTheRequestingConnection(): void
    {
        $dto = BackupSetKeepSignalData::fromArray(
            new BackupSetKeepSignalData('bk-2', true, 'accept-key-2')->toArray(),
        );

        $this->assertSame('bk-2', $dto->backupId);
        $this->assertTrue($dto->keep);
        $this->assertSame('accept-key-2', $dto->initiatorAcceptKey);
    }

    public function testSetKeepSignalHasNoInitiatorWhenAbsent(): void
    {
        $dto = BackupSetKeepSignalData::fromArray([
            BackupSetKeepSignalData::backupId => 'bk-2',
            BackupSetKeepSignalData::keep => true,
        ]);

        $this->assertNull($dto->initiatorAcceptKey);
    }

    public function testRestoreReadsTheArchiveFromABarePayload(): void
    {
        $dto = BackupRestoreActionDTO::fromArray([BackupRestoreActionDTO::backupId => 'bk-3']);

        $this->assertSame('bk-3', $dto->backupId);
        $this->assertSame([BackupRestoreActionDTO::backupId => 'bk-3'], $dto->toArray());
    }

    public function testRestoreUnwrapsTheFieldDataEnvelopeAndTrims(): void
    {
        $dto = BackupRestoreActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [BackupRestoreActionDTO::backupId => '  bk-3  '],
        ]);

        $this->assertSame('bk-3', $dto->backupId);
    }

    public function testRestoreRefusesAPayloadThatNamesNoArchive(): void
    {
        $this->expectException(InvalidFormatException::class);

        BackupRestoreActionDTO::fromArray([]);
    }

    public function testRestoreSignalRoundTripsEveryKeyTheAgentActsOn(): void
    {
        $dto = BackupRestoreSignalData::fromArray(
            new BackupRestoreSignalData('bk-3', 'full', 'allow', 'accept-key-3', 41)->toArray(),
        );

        $this->assertSame('bk-3', $dto->backupId);
        $this->assertSame('full', $dto->scope);
        $this->assertSame('allow', $dto->decision);
        $this->assertSame('accept-key-3', $dto->initiatorAcceptKey);
        $this->assertSame(41, $dto->initiatorUserId);
    }

    public function testRestoreSignalHasNoInitiatorWhenAbsent(): void
    {
        $dto = BackupRestoreSignalData::fromArray([
            BackupRestoreSignalData::backupId => 'bk-3',
            BackupRestoreSignalData::scope => 'full',
            BackupRestoreSignalData::decision => 'allow',
        ]);

        $this->assertNull($dto->initiatorAcceptKey);
        $this->assertNull(
            $dto->initiatorUserId,
            'A CLI restore names no person, and the agent has nobody to photograph identities of',
        );
    }

    public function testRestoreSignalRefusesAnInitiatorThatIsNotAUserId(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(BackupRestoreSignalData::initiatorUserId);

        BackupRestoreSignalData::fromArray([
            BackupRestoreSignalData::backupId => 'bk-3',
            BackupRestoreSignalData::scope => 'full',
            BackupRestoreSignalData::decision => 'allow',
            BackupRestoreSignalData::initiatorUserId => '41',
        ]);
    }

    public function testRestoreSignalRefusesAPayloadCarryingNoVerdict(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(BackupRestoreSignalData::decision);

        BackupRestoreSignalData::fromArray([
            BackupRestoreSignalData::backupId => 'bk-3',
            BackupRestoreSignalData::scope => 'full',
        ]);
    }

    public function testTheProgressFrameIsTheRuntimeRowsOwnSnapshot(): void
    {
        $state = StateRestoreRuntime::fromRow([
            StateRestoreRuntime::running => true,
            StateRestoreRuntime::backupId => 'bk-3',
            StateRestoreRuntime::scope => 'full',
            StateRestoreRuntime::phase => 'importing',
            StateRestoreRuntime::startedAt => '2026-08-15T10:30:00+00:00',
            StateRestoreRuntime::databaseTouched => true,
            StateRestoreRuntime::rehydrateComplete => false,
            StateRestoreRuntime::rehydrateProblems => [],
        ]);
        $view = new RestoreRuntime($state);

        $frame = BackupRestoreProgressSignalData::fromRuntime($view);

        $this->assertSame(
            $view->toArray(),
            $frame->toArray(),
            'The initiator and the CLI monitor are shown one run: the frame carries the row as it is,'
            . ' key for key, so the two representations cannot drift',
        );
    }

    public function testTheProgressFrameRoundTripsOffTheWire(): void
    {
        $frame = BackupRestoreProgressSignalData::fromArray([
            BackupRestoreProgressSignalData::running => false,
            BackupRestoreProgressSignalData::backupId => 'bk-3',
            BackupRestoreProgressSignalData::scope => 'full',
            BackupRestoreProgressSignalData::phase => 'failed',
            BackupRestoreProgressSignalData::startedAt => '2026-08-15T10:30:00+00:00',
            BackupRestoreProgressSignalData::finishedAt => '2026-08-15T10:34:00+00:00',
            BackupRestoreProgressSignalData::outcome => 'error',
            BackupRestoreProgressSignalData::failureReason => 'import failed',
            BackupRestoreProgressSignalData::rehydrateComplete => false,
            BackupRestoreProgressSignalData::rehydrateProblems => ['worker 2: no answer'],
            BackupRestoreProgressSignalData::databaseTouched => true,
        ]);

        $this->assertSame('error', $frame->outcome);
        $this->assertSame(['worker 2: no answer'], $frame->rehydrateProblems);
        $this->assertTrue($frame->databaseTouched);
    }

    public function testAProgressFrameCarryingNoProblemsReadsAsAnEmptyList(): void
    {
        // Every successful restore has this shape, so an absent list is the normal case rather
        // than a broken frame.
        $frame = BackupRestoreProgressSignalData::fromArray([
            BackupRestoreProgressSignalData::running => true,
            BackupRestoreProgressSignalData::rehydrateComplete => false,
            BackupRestoreProgressSignalData::databaseTouched => false,
        ]);

        $this->assertSame([], $frame->rehydrateProblems);
        $this->assertNull($frame->backupId);
    }
}
