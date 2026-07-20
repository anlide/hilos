<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Pages\Backup\DTO\BackupCreateActionDTO;
use Hilos\Pages\Backup\DTO\BackupDeleteActionDTO;
use Hilos\Pages\Backup\DTO\BackupSetKeepActionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the backup list-page action DTOs (HIL-333).
 *
 * Each parses from the raw WebSocket envelope — tolerating the optional FIELD_DATA
 * wrapper — and round-trips its typed fields.
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
}
