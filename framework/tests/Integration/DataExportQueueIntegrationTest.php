<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\DataExport\DataExportState;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\DataExports as ObjectDataExports;
use Hilos\Database\Object\Item\DataExport as ObjectDataExport;
use Hilos\Database\View\Collection\DataExports as ViewDataExports;
use Hilos\Database\View\Item\DataExport as ViewDataExport;
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
        $pending = Hilos::$db->dataExports->actions->order(9, '2026-09-01 00:00:00');

        $readyId = $ready->id;
        $failedId = $failed->id;
        $pendingId = $pending->id;
        self::assertIsInt($readyId);
        self::assertIsInt($failedId);
        self::assertIsInt($pendingId);

        $heldReady = Hilos::$db->dataExports[$readyId];
        $heldFailed = Hilos::$db->dataExports[$failedId];
        $heldPending = Hilos::$db->dataExports[$pendingId];
        self::assertInstanceOf(ViewDataExport::class, $heldReady);
        self::assertInstanceOf(ViewDataExport::class, $heldFailed);
        self::assertInstanceOf(ViewDataExport::class, $heldPending);

        $objectCollection = Hilos::$db->dataExports->getObjectCollection();
        self::assertInstanceOf(ObjectDataExports::class, $objectCollection);
        $readyObject = $objectCollection[$readyId];
        $failedObject = $objectCollection[$failedId];
        $pendingObject = $objectCollection[$pendingId];
        self::assertInstanceOf(ObjectDataExport::class, $readyObject);
        self::assertInstanceOf(ObjectDataExport::class, $failedObject);
        self::assertInstanceOf(ObjectDataExport::class, $pendingObject);

        self::assertTrue($ready->actions->finishReady('ready.zip', 3, '2026-09-01 00:00:00', '2026-09-08 00:00:00'));
        self::assertTrue($failed->actions->finishFailed('2026-09-01 00:00:00', '2026-09-08 00:00:00'));

        $viewAllReady = Hilos::$db->dataExports->allReady();
        self::assertInstanceOf(ViewDataExports::class, $viewAllReady);
        self::assertCount(1, $viewAllReady);
        self::assertArrayHasKey($readyId, $viewAllReady);
        self::assertArrayNotHasKey($failedId, $viewAllReady);
        self::assertArrayNotHasKey($pendingId, $viewAllReady);
        self::assertSame($heldReady, $viewAllReady[$readyId]);
        self::assertSame($readyId, $viewAllReady[$readyId]->id);
        self::assertSame($heldReady, $viewAllReady->first());

        $objectAllReady = $objectCollection->allReady();
        self::assertInstanceOf(ObjectDataExports::class, $objectAllReady);
        self::assertCount(1, $objectAllReady);
        self::assertTrue(isset($objectAllReady[$readyId]));
        self::assertFalse(isset($objectAllReady[$failedId]));
        self::assertFalse(isset($objectAllReady[$pendingId]));
        self::assertSame($readyObject, $objectAllReady[$readyId]);
        self::assertSame($readyId, $objectAllReady[$readyId]->id);
        self::assertSame($readyObject, $objectAllReady->first());

        $emptyViewExpired = Hilos::$db->dataExports->expiredBy('2026-09-07 23:59:59');
        self::assertInstanceOf(ViewDataExports::class, $emptyViewExpired);
        self::assertCount(0, $emptyViewExpired);
        self::assertSame(0, count($emptyViewExpired));
        self::assertNull($emptyViewExpired->first());
        $viewExpiredYielded = false;
        foreach ($emptyViewExpired as $item) {
            $viewExpiredYielded = true;
        }
        self::assertFalse($viewExpiredYielded);
        self::assertNull($emptyViewExpired[$readyId] ?? null);

        $emptyObjectExpired = $objectCollection->expiredBy('2026-09-07 23:59:59');
        self::assertInstanceOf(ObjectDataExports::class, $emptyObjectExpired);
        self::assertCount(0, $emptyObjectExpired);
        self::assertSame(0, count($emptyObjectExpired));
        self::assertNull($emptyObjectExpired->first());
        $objectExpiredYielded = false;
        foreach ($emptyObjectExpired as $item) {
            $objectExpiredYielded = true;
        }
        self::assertFalse($objectExpiredYielded);
        self::assertNull($emptyObjectExpired[$readyId] ?? null);

        $viewExpired = Hilos::$db->dataExports->expiredBy('2026-09-08 00:00:00');
        self::assertInstanceOf(ViewDataExports::class, $viewExpired);
        self::assertCount(2, $viewExpired);
        self::assertArrayHasKey($readyId, $viewExpired);
        self::assertArrayHasKey($failedId, $viewExpired);
        self::assertArrayNotHasKey($pendingId, $viewExpired);
        self::assertSame($heldReady, $viewExpired[$readyId]);
        self::assertSame($heldFailed, $viewExpired[$failedId]);
        self::assertSame($readyId, $viewExpired[$readyId]->id);
        self::assertSame($failedId, $viewExpired[$failedId]->id);

        $objectExpired = $objectCollection->expiredBy('2026-09-08 00:00:00');
        self::assertInstanceOf(ObjectDataExports::class, $objectExpired);
        self::assertCount(2, $objectExpired);
        self::assertTrue(isset($objectExpired[$readyId]));
        self::assertTrue(isset($objectExpired[$failedId]));
        self::assertFalse(isset($objectExpired[$pendingId]));
        self::assertSame($readyObject, $objectExpired[$readyId]);
        self::assertSame($failedObject, $objectExpired[$failedId]);
        self::assertSame($readyId, $objectExpired[$readyId]->id);
        self::assertSame($failedId, $objectExpired[$failedId]->id);

        Hilos::$db->dataExports->actions->deleteForUser(7);
        Hilos::$db->dataExports->actions->deleteForUser(7);
        self::assertNull(Hilos::$db->dataExports->ofUser(7));
        self::assertNull($objectCollection->ofUser(7));

        $emptyViewAllReady = Hilos::$db->dataExports->allReady();
        self::assertInstanceOf(ViewDataExports::class, $emptyViewAllReady);
        self::assertCount(0, $emptyViewAllReady);
        self::assertSame(0, count($emptyViewAllReady));
        self::assertNull($emptyViewAllReady->first());
        $viewAllReadyYielded = false;
        foreach ($emptyViewAllReady as $item) {
            $viewAllReadyYielded = true;
        }
        self::assertFalse($viewAllReadyYielded);
        self::assertNull($emptyViewAllReady[$readyId] ?? null);

        $emptyObjectAllReady = $objectCollection->allReady();
        self::assertInstanceOf(ObjectDataExports::class, $emptyObjectAllReady);
        self::assertCount(0, $emptyObjectAllReady);
        self::assertSame(0, count($emptyObjectAllReady));
        self::assertNull($emptyObjectAllReady->first());
        $objectAllReadyYielded = false;
        foreach ($emptyObjectAllReady as $item) {
            $objectAllReadyYielded = true;
        }
        self::assertFalse($objectAllReadyYielded);
        self::assertNull($emptyObjectAllReady[$readyId] ?? null);
    }
}
