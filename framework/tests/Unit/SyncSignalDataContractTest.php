<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\SyncSignalDataInterface;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncMessageInterface;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncMessageInterface;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Pins the sync type hierarchy the sync path routes by.
 *
 * The path no longer names the concrete payloads in its signatures, so a sync DTO
 * that forgets its `implements` line is no longer rejected at the call site — it
 * would surface as a fatal on live synchronization instead. These assertions
 * catch the missing line at unit-test time.
 */
final class SyncSignalDataContractTest extends TestCase
{
    /** Collection key used by every payload built here. */
    private const string COLLECTION_KEY = 'events';

    public function testRowScopedDbPayloadsImplementTheDbInterface(): void
    {
        foreach ([
            new DbSyncCreatedSignalData(self::COLLECTION_KEY, '1', ['name' => 'Ada']),
            new DbSyncUpdatedSignalData(self::COLLECTION_KEY, '1', ['name' => 'Grace']),
            new DbSyncDeletedSignalData(self::COLLECTION_KEY, '1', ['name' => 'Grace']),
        ] as $signalData) {
            $this->assertInstanceOf(DbSyncSignalDataInterface::class, $signalData);
        }
    }

    public function testStateScopedRtPayloadsImplementTheRtInterface(): void
    {
        foreach ([
            new RtSyncCreatedSignalData(self::COLLECTION_KEY, 'ak-1', ['userId' => 1]),
            new RtSyncUpdatedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
            new RtSyncDeletedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
        ] as $signalData) {
            $this->assertInstanceOf(RtSyncSignalDataInterface::class, $signalData);
        }
    }

    /**
     * A truncate is collection-scoped: it carries neither a row id nor row data, so it
     * belongs to the common interface only. Letting it into the DB sub-interface would
     * hand the row applicators a payload without the fields they read.
     */
    public function testClearedPayloadStaysOutOfTheRowScopedInterface(): void
    {
        $signalData = new DbSyncClearedSignalData(self::COLLECTION_KEY);

        $this->assertInstanceOf(SyncSignalDataInterface::class, $signalData);
        $this->assertNotInstanceOf(DbSyncSignalDataInterface::class, $signalData);
    }

    public function testDbTransportMessagesCarryARowScopedPayload(): void
    {
        foreach ([
            new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(self::COLLECTION_KEY, '1', [])),
            new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(self::COLLECTION_KEY, '1', [])),
            new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(self::COLLECTION_KEY, '1', [])),
        ] as $message) {
            $this->assertInstanceOf(WorkerDbSyncMessageInterface::class, $message);
            $this->assertInstanceOf(DbSyncSignalDataInterface::class, $message->signalData);
        }
    }

    public function testRtTransportMessagesCarryAStateScopedPayload(): void
    {
        foreach ([
            new WorkerRtSyncCreatedMessageDTO(new RtSyncCreatedSignalData(self::COLLECTION_KEY, 'ak-1', [])),
            new WorkerRtSyncUpdatedMessageDTO(new RtSyncUpdatedSignalData(self::COLLECTION_KEY, 'ak-1', [])),
            new WorkerRtSyncDeletedMessageDTO(new RtSyncDeletedSignalData(self::COLLECTION_KEY, 'ak-1', [])),
        ] as $message) {
            $this->assertInstanceOf(WorkerRtSyncMessageInterface::class, $message);
            $this->assertInstanceOf(RtSyncSignalDataInterface::class, $message->signalData);
        }
    }
}
