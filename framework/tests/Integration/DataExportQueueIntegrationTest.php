<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\DataExport\DataExportState;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;

/** Durable queue semantics, including a replaced request's late completion. */
final class DataExportQueueIntegrationTest extends HilosSessionIntegrationTestCase
{
    /**
     * @throws HilosException When the person fixtures cannot be inserted
     */
    protected function setUp(): void
    {
        parent::setUp();
        Database::sqlRun(
            "INSERT INTO `hilos_user` (`id`, `name`) VALUES "
            . "(1, 'Late'), (2, 'First'), (3, 'Second'), (7, 'Ready'), (8, 'Failed'), (9, 'Pending')",
        );
    }

    /**
     * @throws HilosException When a fixture or the queue write fails
     */
    public function testReplacementKeepsOneRequestAndOldBuilderCannotCompleteIt(): void
    {
        $old = Hilos::$db->dataExports->actions->order(7, '2026-09-01 00:00:00');
        $new = Hilos::$db->dataExports->actions->order(7, '2026-09-02 00:00:00');

        self::assertNotSame($old->id, $new->id);
        self::assertSame($new->id, Hilos::$db->dataExports->ofUser(7)?->id);
        self::assertSame(DataExportState::PREPARING, $new->state);
        self::assertFalse($old->actions->finishReady('stale.zip', 123, '2026-09-02 00:01:00', '2026-09-09 00:01:00'));
        self::assertTrue($new->actions->finishReady('current.zip', 456, '2026-09-02 00:02:00', '2026-09-09 00:02:00'));
        self::assertFalse($new->actions->finishFailed('2026-09-02 00:03:00', '2026-09-09 00:03:00'));
        self::assertSame(DataExportState::READY, $new->state);
        self::assertSame('current.zip', $new->storedName);
        self::assertSame(456, $new->sizeBytes);
        Database::sql('SELECT COUNT(*) AS count FROM hilos_data_export WHERE user_id = 7');
        self::assertSame(1, (int)Database::row()['count']);
    }

    /**
     * @throws HilosException When a fixture or the queue write fails
     */
    public function testQueueOrderIgnoresFinishedRequestsAndBreaksTiesById(): void
    {
        $late = Hilos::$db->dataExports->actions->order(1, '2026-09-02 00:00:00');
        $first = Hilos::$db->dataExports->actions->order(2, '2026-09-01 00:00:00');
        $second = Hilos::$db->dataExports->actions->order(3, '2026-09-01 00:00:00');
        self::assertSame($first->id, Hilos::$db->dataExports->nextPreparing()?->id);
        self::assertTrue($first->actions->finishFailed('2026-09-01 00:01:00', '2026-09-08 00:01:00'));
        self::assertSame($second->id, Hilos::$db->dataExports->nextPreparing()?->id);
        $second->actions->delete();
        self::assertSame($late->id, Hilos::$db->dataExports->nextPreparing()?->id);
        $late->actions->delete();
        self::assertNull(Hilos::$db->dataExports->nextPreparing());
        self::assertNull($first->storedName);
        self::assertNull($first->sizeBytes);
    }

    /**
     * @throws HilosException When a fixture or the queue write fails
     */
    public function testReadyAndExpiryQueriesAndForgetting(): void
    {
        $ready = Hilos::$db->dataExports->actions->order(7, '2026-09-01 00:00:00');
        $failed = Hilos::$db->dataExports->actions->order(8, '2026-09-01 00:00:00');
        Hilos::$db->dataExports->actions->order(9, '2026-09-01 00:00:00');
        self::assertTrue($ready->actions->finishReady('ready.zip', 3, '2026-09-01 00:00:00', '2026-09-08 00:00:00'));
        self::assertTrue($failed->actions->finishFailed('2026-09-01 00:00:00', '2026-09-08 00:00:00'));
        self::assertCount(1, Hilos::$db->dataExports->allReady());
        self::assertSame([], Hilos::$db->dataExports->expiredBy('2026-09-07 23:59:59'));
        self::assertCount(2, Hilos::$db->dataExports->expiredBy('2026-09-08 00:00:00'));
        Hilos::$db->dataExports->actions->deleteForUser(7);
        Hilos::$db->dataExports->actions->deleteForUser(7);
        self::assertNull(Hilos::$db->dataExports->ofUser(7));
        self::assertSame([], Hilos::$db->dataExports->allReady());
    }
}
