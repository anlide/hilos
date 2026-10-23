<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDbSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DB replication peer frame (HIL-670).
 *
 * The frame round-trips the origin node and the DB sync signal itself over the wire and is
 * recognised by the peer-frame parser. What it refuses is as much of the contract as what it
 * carries: the frame is applied on the far side by a node that has no way to check the sender,
 * so a payload naming a signal type that is not a DB sync one is a transport error here rather
 * than an apply of somebody else's sync arm there.
 */
final class PeerDbSyncFrameTest extends TestCase
{
    public function testRoundTripsThroughTheWire(): void
    {
        $frame = new PeerDbSyncDTO(
            originNodeId: 'node-A',
            signalType: SignalTypeConstants::DB_SYNC_UPDATED,
            signal: $this->signal(),
        );

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerDbSyncDTO::class, $parsed);
        $this->assertSame(PeerDbSyncDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->originNodeId);
        $this->assertSame(SignalTypeConstants::DB_SYNC_UPDATED, $parsed->signalType);
        $this->assertSame(SignalTypeConstants::DB_SYNC_UPDATED, $parsed->signal->signalType->getType());

        $data = $parsed->signal->data;
        $this->assertInstanceOf(DbSyncUpdatedSignalData::class, $data);
        $this->assertSame('settings', $data->collectionKey);
        $this->assertSame('7', $data->idString);
        $this->assertSame(['title' => 'Ada'], $data->row);
    }

    public function testReceiptMetadataSurvivesPeerAndWorkerWireRoundtrips(): void
    {
        foreach ([
            [
                SignalTypeConstants::DB_SYNC_CREATED,
                SignalConstants::DB_SYNC_CREATED,
                new DbSyncCreatedSignalData(
                    'settings',
                    '7',
                    ['title' => 'Ada'],
                    receiptId: 41,
                    writerAgentId: 'settings:writer',
                ),
                WorkerDbSyncCreatedMessageDTO::class,
            ],
            [
                SignalTypeConstants::DB_SYNC_UPDATED,
                SignalConstants::DB_SYNC_UPDATED,
                new DbSyncUpdatedSignalData(
                    'settings',
                    '7',
                    ['title' => 'Grace'],
                    previous: ['title' => 'Ada'],
                    receiptId: 42,
                    writerAgentId: 'settings:writer',
                ),
                WorkerDbSyncUpdatedMessageDTO::class,
            ],
            [
                SignalTypeConstants::DB_SYNC_DELETED,
                SignalConstants::DB_SYNC_DELETED,
                new DbSyncDeletedSignalData(
                    'settings',
                    '7',
                    ['title' => 'Grace'],
                    receiptId: 43,
                    writerAgentId: 'settings:writer',
                ),
                WorkerDbSyncDeletedMessageDTO::class,
            ],
        ] as [$signalType, $signalName, $signalData, $workerMessageClass]) {
            $frame = new PeerDbSyncDTO(
                originNodeId: 'node-A',
                signalType: $signalType,
                signal: new SignalDTO(
                    new SignalSource(SignalSource::DAEMON),
                    new SignalType($signalType),
                    new SignalName($signalName),
                    $signalData,
                ),
            );

            $peerMessage = PeerDTO::fromWire($frame->toJson());
            $this->assertInstanceOf(PeerDbSyncDTO::class, $peerMessage);
            $replicatedSignalData = $peerMessage->signal->data;
            $this->assertInstanceOf(DbSyncSignalDataInterface::class, $replicatedSignalData);
            $this->assertSame($signalData->receiptId, $replicatedSignalData->receiptId);
            $this->assertSame($signalData->writerAgentId, $replicatedSignalData->writerAgentId);

            $workerMessage = new $workerMessageClass($replicatedSignalData, 'node-A');
            $workerPayload = json_decode($workerMessage->toJson(), true);
            $this->assertIsArray($workerPayload);
            $delivered = $workerMessageClass::fromArray($workerPayload);

            $this->assertSame('node-A', $delivered->originNodeId);
            $this->assertSame($signalData->receiptId, $delivered->signalData->receiptId);
            $this->assertSame($signalData->writerAgentId, $delivered->signalData->writerAgentId);
        }
    }

    public function testRejectsMissingOriginNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer DB sync is missing the origin node id');

        PeerDbSyncDTO::fromArray([
            PeerDbSyncDTO::FIELD_SIGNAL_TYPE => SignalTypeConstants::DB_SYNC_UPDATED,
            PeerDbSyncDTO::FIELD_SIGNAL => $this->signal()->toArray(),
        ]);
    }

    public function testRejectsASignalTypeThatIsNotADbSyncOne(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer DB sync carries a signal type that is not a DB sync one');

        PeerDbSyncDTO::fromArray([
            PeerDbSyncDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerDbSyncDTO::FIELD_SIGNAL_TYPE => SignalTypeConstants::RT_SYNC_CREATED,
            PeerDbSyncDTO::FIELD_SIGNAL => $this->signal()->toArray(),
        ]);
    }

    /**
     * The database replacement is not a DB sync fact this frame may carry. It is a restore, it
     * has a peer protocol and a barrier of its own, and letting it travel here would announce the
     * swap a second time with nothing waiting on the answer.
     */
    public function testRejectsTheDatabaseReplacementFact(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerDbSyncDTO::fromArray([
            PeerDbSyncDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerDbSyncDTO::FIELD_SIGNAL_TYPE => SignalTypeConstants::DB_REHYDRATE,
            PeerDbSyncDTO::FIELD_SIGNAL => $this->signal()->toArray(),
        ]);
    }

    public function testRejectsMissingInnerSignal(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer DB sync is missing the inner signal payload');

        PeerDbSyncDTO::fromArray([
            PeerDbSyncDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerDbSyncDTO::FIELD_SIGNAL_TYPE => SignalTypeConstants::DB_SYNC_UPDATED,
        ]);
    }

    /**
     * Builds the DB sync fact the frame carries.
     *
     * @return SignalDTO Signal announcing one changed database row
     */
    private function signal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::DB_SYNC_UPDATED),
            new SignalName(SignalConstants::DB_SYNC_UPDATED),
            new DbSyncUpdatedSignalData('settings', '7', ['title' => 'Ada']),
        );
    }
}
