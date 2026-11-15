<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Pins that a row sync frame leaves whatever its keys hold. Neither a DB nor an RT frame
 * registers anything: its own echo is known by the emitter stamp alone, so an empty key
 * guards nothing any more, and the frame is broadcast all the same.
 *
 * HIL-468 once turned the emptiness check into an early return, and a frame that could not
 * be deduped took every live table and event-stream update in the chat demo with it.
 */
final class SignalRouterSyncQueueInvariantTest extends TestCase
{
    /** Entity id used wherever the id itself is not what the case is about. */
    private const string ID = '7';

    /** Collection key used wherever the key itself is not what the case is about. */
    private const string COLLECTION_KEY = 'events';

    public function testDbSyncSignalWithoutACollectionIsStillBroadcast(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncSignal(
            SignalConstants::DB_SYNC_CREATED,
            new DbSyncCreatedSignalData('', self::ID, []),
        );

        $this->assertNotNull($router->getNextQueuedSignal());
    }

    public function testDbSyncSignalWithoutAnEntityIdIsStillBroadcast(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncSignal(
            SignalConstants::DB_SYNC_CREATED,
            new DbSyncCreatedSignalData(self::COLLECTION_KEY, '', []),
        );

        $this->assertNotNull($router->getNextQueuedSignal());
    }

    public function testRtSyncSignalWithoutAStateIdIsStillBroadcast(): void
    {
        $router = new SignalRouter();
        $router->queueRtSyncSignal(
            SignalConstants::RT_SYNC_CREATED,
            new RtSyncCreatedSignalData(self::COLLECTION_KEY, '', []),
        );

        $signal = $router->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(RtSyncCreatedSignalData::class, $signal->data);
        $this->assertSame($router->getEmitter(), $signal->data->emitter);
    }

    public function testDbSyncSignalNamingBothPartsIsBroadcastStamped(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncSignal(
            SignalConstants::DB_SYNC_CREATED,
            new DbSyncCreatedSignalData(self::COLLECTION_KEY, self::ID, []),
        );

        $signal = $router->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(DbSyncCreatedSignalData::class, $signal->data);
        $this->assertSame($router->getEmitter(), $signal->data->emitter);
    }

    public function testRtSyncSignalNamingBothPartsIsBroadcastStamped(): void
    {
        $router = new SignalRouter();
        $router->queueRtSyncSignal(
            SignalConstants::RT_SYNC_CREATED,
            new RtSyncCreatedSignalData(self::COLLECTION_KEY, self::ID, []),
        );

        $signal = $router->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(RtSyncCreatedSignalData::class, $signal->data);
        $this->assertSame($router->getEmitter(), $signal->data->emitter);
    }

    public function testDbSyncClearedSignalWithoutACollectionIsNotQueued(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncClearedSignal(new DbSyncClearedSignalData(''));

        $this->assertNull($router->getNextQueuedSignal());
    }
}
