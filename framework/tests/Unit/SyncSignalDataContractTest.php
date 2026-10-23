<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\EmitterStampedSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\SyncSignalDataInterface;
use Hilos\Core\Sync\DTO\SyncSignalDataKey;
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

    /** Receipt primary key carried by metadata cases. */
    private const int RECEIPT_ID = 47;

    /** Agent address carried by metadata cases. */
    private const string WRITER_AGENT_ID = 'chat:7';

    public function testUpdatedPayloadsRoundtripPreviousValues(): void
    {
        $dbPayload = new DbSyncUpdatedSignalData(
            self::COLLECTION_KEY,
            '1',
            ['name' => 'Grace'],
            previous: ['name' => 'Ada'],
        );
        $rtPayload = new RtSyncUpdatedSignalData(
            self::COLLECTION_KEY,
            'ak-1',
            ['presence' => 'online'],
            previous: ['presence' => 'away'],
        );

        $this->assertEquals($dbPayload, DbSyncUpdatedSignalData::fromArray($dbPayload->toArray()));
        $this->assertEquals($rtPayload, RtSyncUpdatedSignalData::fromArray($rtPayload->toArray()));
    }

    public function testRowScopedDbPayloadsImplementTheDbInterface(): void
    {
        foreach ($this->dbRowPayloads(null, null) as $signalData) {
            $this->assertInstanceOf(DbSyncSignalDataInterface::class, $signalData);
        }
    }

    public function testDbRowMetadataSurvivesArrayAndJsonRoundtrips(): void
    {
        foreach ([
            [self::RECEIPT_ID, self::WRITER_AGENT_ID],
            [null, null],
            [self::RECEIPT_ID, null],
        ] as [$receiptId, $writerAgentId]) {
            foreach ($this->dbRowPayloads($receiptId, $writerAgentId) as $signalData) {
                $this->assertSame($receiptId, $signalData->receiptId);
                $this->assertSame($writerAgentId, $signalData->writerAgentId);

                $payload = $signalData->toArray();
                $this->assertArrayHasKey(SyncSignalDataKey::RECEIPT_ID, $payload);
                $this->assertArrayHasKey(SyncSignalDataKey::WRITER_AGENT_ID, $payload);
                $this->assertSame($receiptId, $payload[SyncSignalDataKey::RECEIPT_ID]);
                $this->assertSame($writerAgentId, $payload[SyncSignalDataKey::WRITER_AGENT_ID]);

                $signalDataClass = $signalData::class;
                $this->assertEquals($signalData, $signalDataClass::fromArray($payload));
                $this->assertEquals($signalData, $signalDataClass::fromJson($signalData->toJson()));
            }
        }
    }

    public function testOldDbRowPayloadsDefaultReceiptMetadataToNull(): void
    {
        foreach ($this->dbRowPayloads(self::RECEIPT_ID, self::WRITER_AGENT_ID) as $signalData) {
            $payload = $signalData->toArray();
            unset($payload[SyncSignalDataKey::RECEIPT_ID], $payload[SyncSignalDataKey::WRITER_AGENT_ID]);

            $signalDataClass = $signalData::class;
            $restored = $signalDataClass::fromArray($payload);

            $this->assertNull($restored->receiptId);
            $this->assertNull($restored->writerAgentId);
        }
    }

    public function testDbRowMetadataReadersRejectWrongTypes(): void
    {
        foreach ($this->dbRowPayloads(self::RECEIPT_ID, self::WRITER_AGENT_ID) as $signalData) {
            foreach ([
                [SyncSignalDataKey::RECEIPT_ID, (string) self::RECEIPT_ID],
                [SyncSignalDataKey::WRITER_AGENT_ID, self::RECEIPT_ID],
            ] as [$key, $value]) {
                $payload = $signalData->toArray();
                $payload[$key] = $value;
                $signalDataClass = $signalData::class;

                try {
                    $signalDataClass::fromArray($payload);
                    $this->fail('A DB row payload with mistyped receipt metadata must be refused.');
                } catch (InvalidFormatException $exception) {
                    $this->assertStringContainsString('Payload carries a non-', $exception->getMessage());
                }
            }
        }
    }

    public function testWithEmitterChangesOnlyTheEmitterOnDbRowPayloads(): void
    {
        foreach ($this->dbRowPayloads(self::RECEIPT_ID, self::WRITER_AGENT_ID) as $signalData) {
            $stamped = $signalData->withEmitter('node-b');
            $expected = $signalData->toArray();
            $expected[SyncSignalDataKey::EMITTER] = 'node-b';

            $this->assertSame($expected, $stamped->toArray());
            $this->assertSame(self::RECEIPT_ID, $stamped->receiptId);
            $this->assertSame(self::WRITER_AGENT_ID, $stamped->writerAgentId);
        }
    }

    /**
     * Every DB sync fact carries the identity of the process that broadcast it — the row
     * ones as well as the collection-scoped clear, since HIL-737. The declaration is what
     * lets the send path stamp any of them through one call, so a payload that forgets the
     * line would silently leave unstamped and be applied back to its own sender.
     */
    public function testEveryDbPayloadDeclaresTheEmitterStamp(): void
    {
        foreach ([
            new DbSyncCreatedSignalData(self::COLLECTION_KEY, '1', ['name' => 'Ada']),
            new DbSyncUpdatedSignalData(self::COLLECTION_KEY, '1', ['name' => 'Grace']),
            new DbSyncDeletedSignalData(self::COLLECTION_KEY, '1', ['name' => 'Grace']),
            new DbSyncClearedSignalData(self::COLLECTION_KEY),
        ] as $signalData) {
            $this->assertInstanceOf(EmitterStampedSyncSignalDataInterface::class, $signalData);
        }
    }

    /**
     * RT sync frames stay out of it: they do not travel between nodes and are keyed by
     * the state id alone, so nothing on their path has an emitter to compare.
     */
    public function testRtPayloadsDoNotDeclareTheEmitterStamp(): void
    {
        foreach ([
            new RtSyncCreatedSignalData(self::COLLECTION_KEY, 'ak-1', ['userId' => 1]),
            new RtSyncUpdatedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
            new RtSyncDeletedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
        ] as $signalData) {
            $this->assertNotInstanceOf(EmitterStampedSyncSignalDataInterface::class, $signalData);
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

    public function testClearAndRtPayloadsDoNotDeclareReceiptMetadata(): void
    {
        foreach ([
            new DbSyncClearedSignalData(self::COLLECTION_KEY),
            new RtSyncCreatedSignalData(self::COLLECTION_KEY, 'ak-1', ['userId' => 1]),
            new RtSyncUpdatedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
            new RtSyncDeletedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
        ] as $signalData) {
            $this->assertFalse(property_exists($signalData, 'receiptId'));
            $this->assertFalse(property_exists($signalData, 'writerAgentId'));
        }
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

    /**
     * @param ?int $receiptId Receipt primary key for the test payloads
     * @param ?string $writerAgentId Writing agent address for the test payloads
     * @return list<DbSyncSignalDataInterface> The three row-scoped DB sync payloads
     */
    private function dbRowPayloads(?int $receiptId, ?string $writerAgentId): array
    {
        return [
            new DbSyncCreatedSignalData(
                collectionKey: self::COLLECTION_KEY,
                idString: '1',
                row: ['name' => 'Ada'],
                origin: 'accept-1',
                originRequestId: 'request-1',
                emitter: 'node-a',
                receiptId: $receiptId,
                writerAgentId: $writerAgentId,
            ),
            new DbSyncUpdatedSignalData(
                collectionKey: self::COLLECTION_KEY,
                idString: '1',
                row: ['name' => 'Grace'],
                origin: 'accept-1',
                originRequestId: 'request-1',
                emitter: 'node-a',
                previous: ['name' => 'Ada'],
                receiptId: $receiptId,
                writerAgentId: $writerAgentId,
            ),
            new DbSyncDeletedSignalData(
                collectionKey: self::COLLECTION_KEY,
                idString: '1',
                row: ['name' => 'Grace'],
                origin: 'accept-1',
                originRequestId: 'request-1',
                emitter: 'node-a',
                receiptId: $receiptId,
                writerAgentId: $writerAgentId,
            ),
        ];
    }
}
