<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SelfBroadcastRegistry;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Pins where the non-empty check on a sync payload belongs: it guards the RT self-apply
 * REGISTRATION, never the broadcast. A DB frame registers nothing, since its own echo is
 * known by the emitter stamp alone, so the DB cases here pin only that the frame leaves.
 *
 * The check used to sit inside {@see SelfBroadcastRegistry::register()}, silently doing
 * nothing, while two of the three queue methods called it with no check at all. Moving
 * it up is right; turning it into an early return is not, and HIL-468 did that on its
 * first pass — a payload that cannot be deduped still has to reach the other processes,
 * and dropping it took every live table and event-stream update in the chat demo with it.
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

        $this->assertNotNull($router->getNextQueuedSignal());
        $this->assertFalse($router->shouldSkipRtSyncApply(self::COLLECTION_KEY, ''));
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

    public function testRtSyncSignalNamingBothPartsIsBroadcastAndRegistered(): void
    {
        $router = new SignalRouter();
        $router->queueRtSyncSignal(
            SignalConstants::RT_SYNC_CREATED,
            new RtSyncCreatedSignalData(self::COLLECTION_KEY, self::ID, []),
        );

        $this->assertNotNull($router->getNextQueuedSignal());
        $this->assertTrue($router->shouldSkipRtSyncApply(self::COLLECTION_KEY, self::ID));
    }

    public function testDbSyncClearedSignalWithoutACollectionIsNotQueued(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncClearedSignal(new DbSyncClearedSignalData(''));

        $this->assertNull($router->getNextQueuedSignal());
    }
}
