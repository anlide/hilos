<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Pages\Backup\DTO\BackupCreateActionDTO;
use Hilos\Pages\Backup\DTO\BackupDeleteActionDTO;
use Hilos\Pages\Backup\DTO\BackupSetKeepActionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the backup list-page action DTOs (HIL-333) and the create signal the page
 * hands the agent.
 *
 * Each action DTO parses from the raw WebSocket envelope — tolerating the optional FIELD_DATA
 * wrapper — and round-trips its typed fields. The create signal additionally carries who asked,
 * which is what lets the agent address the run's outcome back to that one connection.
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

    public function testCreateTrimsAndDefaultsAMissingScopeToEmpty(): void
    {
        $this->assertSame('full', BackupCreateActionDTO::fromArray([BackupCreateActionDTO::scope => '  full  '])->scope);
        $this->assertSame('', BackupCreateActionDTO::fromArray([])->scope);
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
            (new BackupCreateSignalData('full', 'accept-key-1'))->toArray(),
        );

        $this->assertSame('full', $dto->scope);
        $this->assertSame('accept-key-1', $dto->initiatorAcceptKey);
    }

    public function testCreateSignalHasNoInitiatorWhenUnattended(): void
    {
        $dto = BackupCreateSignalData::fromArray([BackupCreateSignalData::scope => 'full']);

        $this->assertNull($dto->initiatorAcceptKey);
    }

    public function testCreateSignalTreatsAnEmptyInitiatorAsUnattended(): void
    {
        $dto = BackupCreateSignalData::fromArray([
            BackupCreateSignalData::scope => 'full',
            BackupCreateSignalData::initiatorAcceptKey => '',
        ]);

        $this->assertNull($dto->initiatorAcceptKey);
    }

    public function testDeleteSignalRoundTripsTheRequestingConnection(): void
    {
        $dto = BackupDeleteSignalData::fromArray(
            (new BackupDeleteSignalData('bk-1', 'accept-key-1'))->toArray(),
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
            (new BackupSetKeepSignalData('bk-2', true, 'accept-key-2'))->toArray(),
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
}
