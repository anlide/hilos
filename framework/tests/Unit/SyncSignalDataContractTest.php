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
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncMessageInterface;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
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

    /** Numeric moments survive both signal readers and the worker JSON boundary. */
    public function testCreatedNumericMomentsSurviveSignalAndWorkerReaders(): void
    {
        foreach ([null, 0, -1, -1234567890123, 2147483648001, 1767225600123] as $optional) {
            $row = ['id' => 7, 'occurred_at' => 1767225600123, 'optional_at' => $optional];
            $payload = new DbSyncCreatedSignalData(self::COLLECTION_KEY, '7', $row);
            $worker = new WorkerDbSyncCreatedMessageDTO($payload);
            foreach ([
                DbSyncCreatedSignalData::fromArray($payload->toArray()),
                DbSyncCreatedSignalData::fromJson($payload->toJson()),
                WorkerDbSyncCreatedMessageDTO::fromArray(json_decode($worker->toJson(), true, flags: JSON_THROW_ON_ERROR))->signalData,
            ] as $delivered) {
                self::assertSame($row, $delivered->row);
                $entity = SyncMomentContractEntity::fromRow($delivered->row);
                $entity->applySyncPartialRow($delivered->row);
                self::assertSame($row, $entity->toArray());
            }
        }
    }

    /** Previous zero and negative moments remain numbers when an optional moment is cleared. */
    public function testUpdatedNumericMomentsSurviveSignalAndWorkerReaders(): void
    {
        foreach ([0, -1, -1234567890123, 2147483648001, 1767225600123] as $previousValue) {
            $row = ['occurred_at' => 2147483648001, 'optional_at' => null];
            $previous = ['occurred_at' => $previousValue, 'optional_at' => $previousValue];
            $payload = new DbSyncUpdatedSignalData(self::COLLECTION_KEY, '7', $row, previous: $previous);
            $worker = new WorkerDbSyncUpdatedMessageDTO($payload);
            foreach ([
                DbSyncUpdatedSignalData::fromArray($payload->toArray()),
                DbSyncUpdatedSignalData::fromJson($payload->toJson()),
                WorkerDbSyncUpdatedMessageDTO::fromArray(json_decode($worker->toJson(), true, flags: JSON_THROW_ON_ERROR))->signalData,
            ] as $delivered) {
                self::assertSame($row, $delivered->row);
                self::assertSame($previous, $delivered->previous);
                $entity = SyncMomentContractEntity::fromRow(['id' => 7] + $delivered->previous);
                $entity->applySyncPartialRow($delivered->row);
                $entity->applySyncPartialRow($delivered->row);
                self::assertSame(['id' => 7, 'occurred_at' => 2147483648001, 'optional_at' => null], $entity->toArray());
            }
        }
    }

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

    public function testStampedRtPayloadsRoundtripTheEmitter(): void
    {
        foreach ($this->rtStatePayloads() as $signalData) {
            $roundtripped = $signalData::fromArray($signalData->toArray());

            $this->assertSame('node-a', $roundtripped->emitter);
            $this->assertEquals($signalData, $roundtripped);
        }
    }

    public function testUnstampedRtPayloadsRoundtripAsUnstamped(): void
    {
        foreach ([
            new RtSyncCreatedSignalData(self::COLLECTION_KEY, 'ak-1', ['userId' => 1]),
            new RtSyncUpdatedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
            new RtSyncDeletedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
        ] as $signalData) {
            $this->assertNull($signalData->emitter);
            $this->assertNull($signalData::fromArray($signalData->toArray())->emitter);
        }
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

    public function testWithEmitterChangesOnlyTheEmitterOnRtStatePayloads(): void
    {
        foreach ($this->rtStatePayloads() as $signalData) {
            $stamped = $signalData->withEmitter('node-b');
            $expected = $signalData->toArray();
            $expected[SyncSignalDataKey::EMITTER] = 'node-b';

            $this->assertSame($signalData::class, $stamped::class);
            $this->assertSame($expected, $stamped->toArray());
            $this->assertSame('node-a', $signalData->emitter);
            if ($signalData instanceof RtSyncUpdatedSignalData) {
                $this->assertSame(['presence' => 'away'], $stamped->previous);
            }
        }
    }

    /**
     * A stamped copy hands the row and the previous values on in the form the original holds
     * them: stamping a segmented carrier must not materialize it.
     */
    public function testWithEmitterKeepsTheSegmentedCarrierOfAnRtPayload(): void
    {
        $row = RtRowValues::fromNative(['presence' => 'online']);
        $previous = RtRowValues::fromNative(['presence' => 'away']);

        $updated = (new RtSyncUpdatedSignalData(self::COLLECTION_KEY, 'ak-1', $row, previous: $previous))->withEmitter('node-b');
        $created = (new RtSyncCreatedSignalData(self::COLLECTION_KEY, 'ak-1', $row))->withEmitter('node-b');

        $this->assertSame($row, $updated->carriedRow());
        $this->assertSame($previous, $updated->carriedPrevious());
        $this->assertSame($row, $created->carriedRow());
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
     * RT sync frames travel between processes and between nodes, and the receiver knows
     * its own echo by the stamp, so every one of them declares it as the DB ones do.
     */
    public function testRtPayloadsDeclareTheEmitterStamp(): void
    {
        foreach ([
            new RtSyncCreatedSignalData(self::COLLECTION_KEY, 'ak-1', ['userId' => 1]),
            new RtSyncUpdatedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
            new RtSyncDeletedSignalData(self::COLLECTION_KEY, 'ak-1', ['presence' => 'online']),
        ] as $signalData) {
            $this->assertInstanceOf(EmitterStampedSyncSignalDataInterface::class, $signalData);
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

    public function testRtStreamOperationsCarryAStateScopedPayload(): void
    {
        foreach ([
            new RtSyncCreatedSignalData(self::COLLECTION_KEY, 'ak-1', []),
            new RtSyncUpdatedSignalData(self::COLLECTION_KEY, 'ak-1', []),
            new RtSyncDeletedSignalData(self::COLLECTION_KEY, 'ak-1', []),
        ] as $data) {
            $carried = RtSyncStreamOperation::fromSignalData($data, 1)->signalData();
            $this->assertInstanceOf($data::class, $carried);
            $this->assertInstanceOf(RtSyncSignalDataInterface::class, $carried);
            $this->assertSame([self::COLLECTION_KEY, 'ak-1'], [$carried->collectionKey, $carried->stateId]);
            $this->assertNull($carried->emitter);
        }
    }

    public function testRtStreamOperationsCarryTheEmitterStamp(): void
    {
        foreach ($this->rtStatePayloads() as $data) {
            $operation = RtSyncStreamOperation::fromSignalData($data, 1);
            $carried = $operation->signalData();

            $this->assertSame('node-a', $operation->emitter);
            $this->assertSame($data::class, $carried::class);
            $this->assertSame('node-a', $carried->emitter);
        }
    }

    /**
     * @return list<RtSyncSignalDataInterface> The three state-scoped RT sync payloads, each stamped by node-a
     */
    private function rtStatePayloads(): array
    {
        return [
            new RtSyncCreatedSignalData(
                collectionKey: self::COLLECTION_KEY,
                stateId: 'ak-1',
                row: ['presence' => 'online'],
                origin: 'accept-1',
                originRequestId: 'request-1',
                emitter: 'node-a',
            ),
            new RtSyncUpdatedSignalData(
                collectionKey: self::COLLECTION_KEY,
                stateId: 'ak-1',
                row: ['presence' => 'online'],
                origin: 'accept-1',
                originRequestId: 'request-1',
                previous: ['presence' => 'away'],
                emitter: 'node-a',
            ),
            new RtSyncDeletedSignalData(
                collectionKey: self::COLLECTION_KEY,
                stateId: 'ak-1',
                row: ['presence' => 'online'],
                origin: 'accept-1',
                originRequestId: 'request-1',
                emitter: 'node-a',
            ),
        ];
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

/** Mapped numeric row at the receiving end of DB_SYNC. */
final class SyncMomentContractEntity extends Entity
{
    public const string id = 'id';
    public const string occurred_at = 'occurred_at';
    public const string optional_at = 'optional_at';

    public const string _table = 'sync_moment_contract_fixture';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::occurred_at, self::optional_at];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::occurred_at => PhpType::INTEGER->value,
        self::optional_at => PhpType::INTEGER->value,
    ];
    public const array _moments = [self::occurred_at, self::optional_at];

    public int $id;
    public int $occurred_at;
    public ?int $optional_at;
}
