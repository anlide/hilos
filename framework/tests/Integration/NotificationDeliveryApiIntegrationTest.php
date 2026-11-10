<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\NotificationDeliveriesActions;
use Hilos\Database\Actions\Item\NotificationDeliveryActions;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\View\Item\NotificationDelivery;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\NotificationDeliveryIdentity;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;

#[IntegrationTest]
final class NotificationDeliveryApiIntegrationTest extends NotificationDeliveryApiIntegrationTestCase
{
    /** Default NULL, zero and large original dimensions survive the very first insert. */
    public function testCreatePendingPersistsOriginalDimensionAndLegacyDefault(): void
    {
        self::assertInstanceOf(NotificationDeliveriesActions::class, Hilos::$db->notificationDeliveries->actions);
        $legacy = Hilos::$db->notificationDeliveries->actions->createPending(11, 'legacy-channel');
        self::assertInstanceOf(NotificationDelivery::class, $legacy);
        self::assertIsInt($legacy->id);
        self::assertNull($legacy->routingKey);
        self::assertSame([
            ['routing_key' => null, 'status' => DeliveryStatus::PENDING, 'attempts' => '0'],
        ], Database::sql(
            'SELECT routing_key, status, attempts FROM hilos_notification_delivery WHERE id = ?',
            [$legacy->id],
        )->rows());

        foreach ([null, 0, 4294967295, PHP_INT_MAX] as $index => $key) {
            $delivery = Hilos::$db->notificationDeliveries->actions->createPending(20 + $index, 'test-channel', $key);
            self::assertIsInt($delivery->id);
            self::assertSame($key, $delivery->routingKey);
            self::assertSame([
                ['notification_id' => (string)(20 + $index), 'channel' => 'test-channel',
                    'routing_key' => $key === null ? null : (string)$key],
            ], Database::sql(
                'SELECT notification_id, channel, routing_key FROM hilos_notification_delivery WHERE id = ?',
                [$delivery->id],
            )->rows());
            self::assertEquals(new NotificationDeliveryIdentity($delivery->id, 20 + $index, 'test-channel', $key),
                Hilos::$db->notificationDeliveries->readIdentity($delivery->id));
        }

        $legacyObject = Hilos::$db->notificationDeliveries->getObjectCollection()->createPending(31, 'legacy-object-channel');
        self::assertNull($legacyObject->routingKey);
        self::assertSame([['routing_key' => null]], Database::sql(
            'SELECT routing_key FROM hilos_notification_delivery WHERE id = ?', [$legacyObject->id],
        )->rows());
    }

    /** @return iterable<string, array{bool}> Both supported creation entrances reject a negative key */
    public static function creationEntrances(): iterable
    {
        yield 'collection action' => [true];
        yield 'Object primitive' => [false];
    }

    /** @param bool $throughAction Whether to enter through the mounted action */
    #[DataProvider('creationEntrances')]
    public function testNegativeDimensionIsRefusedBeforeInsert(bool $throughAction): void
    {
        try {
            if ($throughAction) {
                Hilos::$db->notificationDeliveries->actions->createPending(11, 'test-channel', -1);
            } else {
                Hilos::$db->notificationDeliveries->getObjectCollection()->createPending(11, 'test-channel', -1);
            }
            self::fail('A negative routing dimension must be refused');
        } catch (ValidationException) {
            self::assertSame([], Database::sql('SELECT id FROM hilos_notification_delivery')->rows());
        }
    }

    /** Exercises the real bounded reader, including a live parent and soft orphan references. */
    public function testPendingPagesFilterChannelAndStatusAndKeepOrphansInIdOrder(): void
    {
        Database::sql("INSERT INTO hilos_user (id, name) VALUES (1893, 'Delivery recipient')");
        Database::sql(
            'INSERT INTO hilos_notification (id, user_id, type, title, `created_at`) VALUES (?, ?, ?, ?, 1767225600123)',
            [11, 1893, 'test.notification', 'Live parent'],
        );
        $first = Hilos::$db->notificationDeliveries->actions->createPending(11, 'test-channel', 55)->id;
        $orphan = Hilos::$db->notificationDeliveries->actions->createPending(12, 'test-channel', null)->id;
        Hilos::$db->notificationDeliveries->actions->createPending(13, 'other-channel', 55);
        $sent = Hilos::$db->notificationDeliveries->actions->createPending(14, 'test-channel', 55)->id;
        Hilos::$db->notificationDeliveries[$sent]->actions->markSent();
        $failed = Hilos::$db->notificationDeliveries->actions->createPending(15, 'test-channel', 55)->id;
        Hilos::$db->notificationDeliveries[$failed]->actions->beginAttempt();
        Hilos::$db->notificationDeliveries[$failed]->actions->recordFailure('terminal failure', 1);
        $last = Hilos::$db->notificationDeliveries->actions->createPending(16, 'test-channel', 77)->id;

        $page = Hilos::$db->notificationDeliveries->pendingIdentitiesAfter('test-channel', 0, 2);
        self::assertInstanceOf(ChangeLogReadCollection::class, $page);
        self::assertCount(2, $page);
        self::assertSame([0, 1], array_keys($page->toArray()));
        self::assertSame([$first, $orphan], array_map(
            static fn(NotificationDeliveryIdentity $identity): int => $identity->deliveryId,
            $page->toArray(),
        ));
        self::assertSame(11, $page->first()->notificationId);
        self::assertSame(12, $page->last()->notificationId);
        self::assertNull($page->last()->routingKey);
        self::assertSame('test-channel', $page->last()->channel);
        self::assertSame($orphan, Hilos::$db->notificationDeliveries->readIdentity($orphan)->deliveryId);

        $walks = [];
        foreach ($page as $outerKey => $outerIdentity) {
            foreach ($page as $innerKey => $innerIdentity) {
                $walks[] = [$outerKey, $outerIdentity->deliveryId, $innerKey, $innerIdentity->deliveryId];
            }
        }
        self::assertSame([
            [0, $first, 0, $first], [0, $first, 1, $orphan],
            [1, $orphan, 0, $first], [1, $orphan, 1, $orphan],
        ], $walks);
        $copy = $page->toArray();
        unset($copy[0]);
        self::assertCount(2, $page);

        $next = Hilos::$db->notificationDeliveries->pendingIdentitiesAfter('test-channel', $orphan, 2);
        self::assertCount(1, $next);
        self::assertSame($last, $next->first()->deliveryId);
        $objectPage = Hilos::$db->notificationDeliveries->getObjectCollection()
            ->pendingIdentitiesAfter('test-channel', $first, 1);
        self::assertCount(1, $objectPage);
        self::assertSame($orphan, $objectPage->first()->deliveryId);
        foreach (['test-channel', 'absent-channel'] as $channel) {
            $empty = Hilos::$db->notificationDeliveries->pendingIdentitiesAfter($channel, $last, 2);
            self::assertTrue($empty->isEmpty());
            self::assertCount(0, $empty);
            self::assertSame([], iterator_to_array($empty));
            self::assertNull($empty->first());
            self::assertNull($empty->last());
        }

        Hilos::$db->notificationDeliveries[$first]->actions->markSent();
        self::assertSame($first, $page->first()->deliveryId);
        self::assertSame($orphan, Hilos::$db->notificationDeliveries
            ->pendingIdentitiesAfter('test-channel', 0, 1)->first()->deliveryId);
    }

    /** @return iterable<string, array{int}> No request may turn the scan into an unbounded read */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /** @param int $limit Invalid page size */
    #[DataProvider('invalidLimits')]
    public function testPendingScanRefusesNonpositiveLimits(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        Hilos::$db->notificationDeliveries->pendingIdentitiesAfter('test-channel', 0, $limit);
    }

    /** Each mounted item action delegates the existing transition and persists its own outcome. */
    public function testItemActionsPersistAttemptsFailureResetAndSettlement(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, 'test-channel', 91)->id;
        self::assertInstanceOf(NotificationDeliveryActions::class, Hilos::$db->notificationDeliveries[$id]->actions);
        Hilos::$db->notificationDeliveries[$id]->actions->beginAttempt();
        Hilos::$db->notificationDeliveries[$id]->actions->recordFailure('retry later', 2);
        self::assertSame([
            ['status' => DeliveryStatus::PENDING, 'attempts' => '1', 'last_error' => 'retry later', 'delivered_at' => null],
        ], Database::sql(
            'SELECT status, attempts, last_error, delivered_at FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());

        Hilos::$db->notificationDeliveries[$id]->actions->beginAttempt();
        Hilos::$db->notificationDeliveries[$id]->actions->recordFailure(str_repeat('x', 300), 2);
        self::assertSame([
            ['status' => DeliveryStatus::FAILED, 'attempts' => '2', 'last_error' => str_repeat('x', 255)],
        ], Database::sql('SELECT status, attempts, last_error FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());

        Hilos::$db->notificationDeliveries[$id]->actions->resetForRetry();
        self::assertSame([
            ['status' => DeliveryStatus::PENDING, 'attempts' => '0', 'last_error' => null, 'delivered_at' => null],
        ], Database::sql(
            'SELECT status, attempts, last_error, delivered_at FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());

        Hilos::$db->notificationDeliveries[$id]->actions->beginAttempt();
        Hilos::$db->notificationDeliveries[$id]->actions->markSent();
        $row = Database::sql(
            'SELECT status, attempts, routing_key, delivered_at, updated_at FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->firstRow();
        self::assertSame(DeliveryStatus::SENT, $row['status']);
        self::assertSame('1', $row['attempts']);
        self::assertSame('91', $row['routing_key']);
        self::assertNotNull($row['delivered_at']);
        self::assertSame($row['delivered_at'], $row['updated_at']);
    }

    /** A real Add-only context creates but cannot use any Update/Remove item entry. */
    public function testCreateAndEveryItemActionHonorTheExecutionGrant(): void
    {
        $agentId = 'delivery-add-only';
        TruthSourceRegistry::registerCreate(HilosDbContext::notificationDeliveries, $agentId);
        $token = ExecutionContext::push(new ExecutionFrame(agentId: $agentId));
        try {
            $id = Hilos::$db->notificationDeliveries->actions->createPending(11, 'test-channel', 5)->id;
            $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows();
            foreach (['beginAttempt', 'markSent', 'recordFailure', 'resetForRetry', 'delete'] as $method) {
                try {
                    if ($method === 'recordFailure') {
                        Hilos::$db->notificationDeliveries[$id]->actions->recordFailure('must not be saved', 1);
                    } else {
                        Hilos::$db->notificationDeliveries[$id]->actions->$method();
                    }
                    self::fail('An Add-only grant must refuse ' . $method);
                } catch (WriteNotAllowedException) {
                    self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
                    self::assertSame(DeliveryStatus::PENDING, Hilos::$db->notificationDeliveries[$id]->status);
                    self::assertSame(0, Hilos::$db->notificationDeliveries[$id]->attempts);
                }
            }
        } finally {
            ExecutionContext::pop($token);
            TruthSourceRegistry::unregisterAgent($agentId);
        }
    }

    /** An Update-only writer cannot create or remove, and the Remove writer drops cached membership too. */
    public function testSeparateUpdateAndRemoveGrantsAndDeleteInvalidateTheHeldWrapper(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, 'test-channel', 9)->id;
        $held = Hilos::$db->notificationDeliveries[$id];
        self::assertSame($held, Hilos::$db->notificationDeliveries[$id]);
        $agentId = 'delivery-update-only';
        TruthSourceRegistry::register(
            HilosDbContext::notificationDeliveries, TruthSourceKeys::all(), $agentId,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        $token = ExecutionContext::push(new ExecutionFrame(agentId: $agentId));
        try {
            Hilos::$db->notificationDeliveries[$id]->actions->beginAttempt();
            Hilos::$db->notificationDeliveries[$id]->actions->recordFailure('failed', 1);
            Hilos::$db->notificationDeliveries[$id]->actions->resetForRetry();
            Hilos::$db->notificationDeliveries[$id]->actions->markSent();
            try {
                Hilos::$db->notificationDeliveries->actions->createPending(12, 'test-channel', 9);
                self::fail('An Update-only grant must not insert');
            } catch (CreateNotAllowedException) {
                self::assertCount(1, Database::sql('SELECT id FROM hilos_notification_delivery')->rows());
            }
            try {
                Hilos::$db->notificationDeliveries[$id]->actions->delete();
                self::fail('An Update-only grant must not remove');
            } catch (WriteNotAllowedException) {
                self::assertArrayHasKey($id, Hilos::$db->notificationDeliveries);
            }
        } finally {
            ExecutionContext::pop($token);
            TruthSourceRegistry::unregisterAgent($agentId);
        }

        $agentId = 'delivery-remove-only';
        TruthSourceRegistry::register(
            HilosDbContext::notificationDeliveries, TruthSourceKeys::listed((string)$id), $agentId,
            TruthSourceOperations::of(TruthSourceOperation::Remove),
        );
        $token = ExecutionContext::push(new ExecutionFrame(agentId: $agentId));
        try {
            Hilos::$db->notificationDeliveries[$id]->actions->delete();
            self::assertSame([], Database::sql('SELECT id FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
            self::assertArrayNotHasKey($id, Hilos::$db->notificationDeliveries->getObjectCollection());
            self::assertArrayNotHasKey($id, Hilos::$db->notificationDeliveries);
            self::assertNull(Hilos::$db->notificationDeliveries->readIdentity($id));
            self::assertCount(0, Hilos::$db->notificationDeliveries->pendingIdentitiesAfter('test-channel', 0, 2));
            $this->expectException(ItemNotFoundException::class);
            self::assertSame($held, Hilos::$db->notificationDeliveries[$id]);
        } finally {
            ExecutionContext::pop($token);
            TruthSourceRegistry::unregisterAgent($agentId);
        }
    }
}
