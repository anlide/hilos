<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SelfBroadcastRegistry;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Covers the HIL-737 fault: self-echo suppression for a ROW sync was keyed by the row
 * alone. Any process writing that row while this one was still awaiting the echo of its
 * own write read as this one's echo, and two changes were lost at once — the foreign
 * write was dropped as an own echo, and it spent the {@see SelfBroadcastRegistry} note
 * standing for this process's write, so this process's own echo then passed through
 * unsuppressed and reached the agents as someone else's fact.
 *
 * The verdict now takes the emitter stamp the payload carries, and reads the registry
 * only once that stamp matches.
 */
final class DbSyncRowSelfEchoEmitterTest extends TestCase
{
    /** Collection key every row sync here is queued for. */
    private const string COLLECTION_KEY = 'events';

    /** Row id the cases are about. */
    private const string ID = '7';

    public function testForeignRowSyncIsAppliedAndLeavesOurRegistrationStanding(): void
    {
        $router = new SignalRouter();
        $this->queueOwnRowSync($router);

        $this->assertFalse($router->shouldSkipDbSyncApply(self::COLLECTION_KEY, self::ID, 'some-other-process'));
        // The note outlived the foreign fact, so this process's own echo is still caught.
        $this->assertTrue($router->shouldSkipDbSyncApply(self::COLLECTION_KEY, self::ID, $router->getEmitter()));
    }

    /**
     * An unstamped row sync counts as someone else's, exactly as an unstamped clear does.
     * The CLI `db:announce` sends one on purpose and must reach every process.
     */
    public function testUnstampedRowSyncIsApplied(): void
    {
        $router = new SignalRouter();
        $this->queueOwnRowSync($router);

        $this->assertFalse($router->shouldSkipDbSyncApply(self::COLLECTION_KEY, self::ID, null));
    }

    public function testQueuedRowSyncCarriesThisProcessEmitter(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncSignal(
            SignalTypeConstants::DB_SYNC_CREATED,
            new DbSyncCreatedSignalData(self::COLLECTION_KEY, self::ID, ['id' => 7], 'accept-key-7'),
        );

        $signal = $router->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $signalData = $signal->data;
        $this->assertInstanceOf(DbSyncCreatedSignalData::class, $signalData);

        $this->assertSame($router->getEmitter(), $signalData->emitter);
        // The accept key of the writing connection travels separately and is untouched.
        $this->assertSame('accept-key-7', $signalData->origin);
        // The verdict is read off the payload that actually left, not off the router's own
        // identity: an unstamped send would read back as another process's write.
        $this->assertTrue(
            $router->shouldSkipDbSyncApply($signalData->collectionKey, $signalData->idString, $signalData->emitter),
        );
    }

    /**
     * The stamp only works if it survives the pipe. Losing either the toArray() line or the
     * fromArray() one would leave every row frame unstamped, every frame would then read as
     * someone else's, and self-echo suppression would be dead without a single failing
     * assertion above — those read the payload object, this one reads what actually travels.
     */
    public function testReceiptMetadataAndEmitterSurviveEveryWorkerMessageRoundtrip(): void
    {
        foreach ([
            [
                SignalTypeConstants::DB_SYNC_CREATED,
                new DbSyncCreatedSignalData(
                    self::COLLECTION_KEY,
                    self::ID,
                    ['id' => 7],
                    receiptId: 47,
                    writerAgentId: 'chat:7',
                ),
                WorkerDbSyncCreatedMessageDTO::class,
            ],
            [
                SignalTypeConstants::DB_SYNC_UPDATED,
                new DbSyncUpdatedSignalData(
                    self::COLLECTION_KEY,
                    self::ID,
                    ['name' => 'Grace'],
                    previous: ['name' => 'Ada'],
                    receiptId: 47,
                    writerAgentId: 'chat:7',
                ),
                WorkerDbSyncUpdatedMessageDTO::class,
            ],
            [
                SignalTypeConstants::DB_SYNC_DELETED,
                new DbSyncDeletedSignalData(
                    self::COLLECTION_KEY,
                    self::ID,
                    ['name' => 'Grace'],
                    receiptId: 47,
                    writerAgentId: 'chat:7',
                ),
                WorkerDbSyncDeletedMessageDTO::class,
            ],
        ] as [$signalType, $payload, $workerMessageClass]) {
            $router = new SignalRouter();
            $router->queueDbSyncSignal($signalType, $payload);

            $signal = $router->getNextQueuedSignal();
            $this->assertNotNull($signal);
            $signalData = $signal->data;
            $this->assertInstanceOf(DbSyncSignalDataInterface::class, $signalData);

            // The daemon fans the row sync out to workers as this message, serialized as JSON.
            $transported = json_decode((new $workerMessageClass($signalData))->toJson(), true);
            $this->assertIsArray($transported);
            $delivered = $workerMessageClass::fromArray($transported)->signalData;

            $this->assertSame($router->getEmitter(), $delivered->emitter);
            $this->assertSame(47, $delivered->receiptId);
            $this->assertSame('chat:7', $delivered->writerAgentId);
            $this->assertTrue(
                $router->shouldSkipDbSyncApply(
                    $delivered->collectionKey,
                    $delivered->idString,
                    $delivered->emitter,
                ),
            );
        }
    }

    /**
     * Queues a row sync of this process, leaving its self-broadcast note pending.
     *
     * @param SignalRouter $router Router the sync is queued on
     */
    private function queueOwnRowSync(SignalRouter $router): void
    {
        $router->queueDbSyncSignal(
            SignalTypeConstants::DB_SYNC_CREATED,
            new DbSyncCreatedSignalData(self::COLLECTION_KEY, self::ID, ['id' => 7]),
        );
    }
}
