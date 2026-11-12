<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Communications\HilosNotificationDeliveryTableRow;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * The retry reset judges the saved delivery row, never the object this process holds for it.
 *
 * Every case leaves a held delivery object or the notification mirror behind the table on
 * purpose - by raw SQL, the way another process's write looks to a process that has not heard
 * of it yet - so the class asks for the cold ORM reset after its rollback. The reset runs
 * through the real mounted item action under the mail shard owner's Update claim, and the
 * owner's own retry branch is driven the way its worker hands it the library's request.
 */
#[IntegrationTest]
final class DeliveryRetryOwnerIntegrationTest extends DeliveryRetryOwnerIntegrationTestCase implements BreaksOrmState
{
    /** A held pending object must not leave the saved failure in place: the diff starts from the saved row. */
    public function testFailedRowBehindAHeldPendingObjectIsResetFromItsSavedValues(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending($this->notificationId, MailDeliveryChannel::NAME, 0)->id;
        $held = Hilos::$db->notificationDeliveries[$id];
        Database::sql(
            'UPDATE hilos_notification_delivery SET status = ?, attempts = 3, last_error = ?, updated_at = 2 WHERE id = ?',
            [DeliveryStatus::FAILED, 'smtp down', $id],
        );
        self::assertSame(DeliveryStatus::PENDING, $held->status);
        $this->changes->list = [];

        $this->resetAsOwner($id);

        $row = Database::sql(
            'SELECT status, attempts, last_error, delivered_at, updated_at FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->firstRow();
        self::assertSame(
            ['status' => DeliveryStatus::PENDING, 'attempts' => '0', 'last_error' => null, 'delivered_at' => null],
            array_diff_key($row, ['updated_at' => true]),
        );
        self::assertGreaterThan(2, (int)$row['updated_at']);
        self::assertSame($held, Hilos::$db->notificationDeliveries[$id]);
        self::assertSame([DeliveryStatus::PENDING, 0, null], [$held->status, $held->attempts, $held->lastError]);

        self::assertCount(1, $this->changes->list);
        $change = $this->changes->list[0];
        self::assertSame([(string)$id, TableMutationType::Update], [$change->sourceId, $change->mutationType]);
        self::assertSame([self::ACCEPT_KEY, self::REQUEST_ID], [$change->origin, $change->originRequestId]);
        $columns = [EntityNotificationDelivery::status, EntityNotificationDelivery::attempts,
            EntityNotificationDelivery::last_error, EntityNotificationDelivery::updated_at];
        self::assertEqualsCanonicalizing($columns, array_keys($change->row));
        self::assertEqualsCanonicalizing($columns, array_keys($change->previous));
        self::assertSame(
            [DeliveryStatus::PENDING, 0, null],
            [$change->row['status'], $change->row['attempts'], $change->row['last_error']],
        );
        self::assertSame(
            [DeliveryStatus::FAILED, 3, 'smtp down', 2],
            [$change->previous['status'], $change->previous['attempts'], $change->previous['last_error'], $change->previous['updated_at']],
        );

        $mutation = new HilosNotificationDeliveriesTable()->buildMutationForSourceEvent($change);
        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        $journal = $mutation->row;
        self::assertInstanceOf(HilosNotificationDeliveryTableRow::class, $journal);
        self::assertSame(
            [$id, DeliveryStatus::PENDING, 0, null, null, 'Retry notification'],
            [$journal->rowKey, $journal->status, $journal->attempts, $journal->lastError, $journal->deliveredAt, $journal->notificationTitle],
        );
    }

    /** @return iterable<string, array{string}> What the held object's id leads to by the time the reset arrives */
    public static function heldObjectStates(): iterable
    {
        yield 'recreated failed under a held pending object' => ['recreated failed'];
        yield 'recreated pending under a held failed object' => ['recreated pending'];
        yield 'deleted under a held failed object' => ['deleted'];
        yield 'dirty coordinate on the held object' => ['dirty coordinate'];
    }

    /**
     * The id is followed to the row it holds now; a row gone stays gone, and a rewritten coordinate is refused before refresh.
     *
     * @param string $state What happened to the row or to the held object before the reset
     */
    #[DataProvider('heldObjectStates')]
    public function testHeldObjectIsJudgedByTheRowItsIdHoldsNow(string $state): void
    {
        $id = $state === 'recreated failed'
            ? Hilos::$db->notificationDeliveries->actions->createPending($this->notificationId, MailDeliveryChannel::NAME, 0)->id
            : $this->failedDelivery();
        $object = Hilos::$db->notificationDeliveries->getObjectCollection()[$id];
        if ($state === 'recreated failed' || $state === 'recreated pending') {
            Database::sql('DELETE FROM hilos_notification_delivery WHERE id = ?', [$id]);
            Database::sql(
                'INSERT INTO hilos_notification_delivery'
                . ' (id, notification_id, channel, routing_key, status, attempts, last_error, created_at, updated_at)'
                . ' VALUES (?, ?, ?, 0, ?, 2, ?, 7, 7)',
                [$id, $this->notificationId, MailDeliveryChannel::NAME,
                    $state === 'recreated failed' ? DeliveryStatus::FAILED : DeliveryStatus::PENDING, 'recreated'],
            );
        } elseif ($state === 'deleted') {
            Database::sql('DELETE FROM hilos_notification_delivery WHERE id = ?', [$id]);
        } else {
            new ReflectionProperty(Object_::class, 'entity')->getValue($object)->notification_id = $this->notificationId + 1;
        }
        $this->changes->list = [];

        switch ($state) {
            case 'recreated failed':
                $this->resetAsOwner($id);
                self::assertSame([['status' => DeliveryStatus::PENDING, 'attempts' => '0', 'last_error' => null, 'created_at' => '7']],
                    Database::sql('SELECT status, attempts, last_error, created_at FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
                self::assertSame([DeliveryStatus::PENDING, 7], [$object->status, $object->createdAt]);
                self::assertSame([DeliveryStatus::FAILED, 2, 'recreated'], [$this->changes->list[0]->previous['status'],
                    $this->changes->list[0]->previous['attempts'], $this->changes->list[0]->previous['last_error']]);
                break;
            case 'recreated pending':
                $this->assertResetRefused($id, 'Only failed deliveries can be retried');
                self::assertSame([DeliveryStatus::PENDING, 7], [$object->status, $object->createdAt]);
                break;
            case 'deleted':
                $this->assertResetRefused($id, "Unknown delivery: {$id}");
                self::assertSame([], Database::sql('SELECT id FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
                break;
            case 'dirty coordinate':
                $this->asClaimant(self::mailOwner(), function () use ($id): void {
                    $this->assertRefusedWithoutChanges(
                        static fn () => Hilos::$db->notificationDeliveries[$id]->actions->resetForRetry(), self::mailOwner(),
                    );
                });
                self::assertSame([$this->notificationId + 1, DeliveryStatus::FAILED], [$object->notificationId, $object->status]);
                break;
        }
    }

    /** @return iterable<string, array{string}> Saved states a held failure must not stand in for */
    public static function unfailedStates(): iterable
    {
        yield 'pending behind a held failure' => [DeliveryStatus::PENDING];
        yield 'sent behind a held failure' => [DeliveryStatus::SENT];
        yield 'repeated after a reset' => ['repeated'];
    }

    /** @param string $state Saved status behind the held failure, or a second request after a reset */
    #[DataProvider('unfailedStates')]
    public function testResetOfARowThatIsNotFailedIsRefusedWithoutChanges(string $state): void
    {
        if ($state === 'repeated') {
            $id = Hilos::$db->notificationDeliveries->actions->createPending($this->notificationId, MailDeliveryChannel::NAME, 0)->id;
            Database::sql('UPDATE hilos_notification_delivery SET status = ?, attempts = 3 WHERE id = ?', [DeliveryStatus::FAILED, $id]);
            $this->changes->list = [];
            $this->resetAsOwner($id);
            $expected = DeliveryStatus::PENDING;
        } else {
            $id = $this->failedDelivery();
            Database::sql('UPDATE hilos_notification_delivery SET status = ?, delivered_at = 5 WHERE id = ?', [$state, $id]);
            $this->changes->list = [];
            $expected = $state;
        }

        $this->assertResetRefused($id, 'Only failed deliveries can be retried');
        self::assertSame($expected, Hilos::$db->notificationDeliveries[$id]->status);
        self::assertCount($state === 'repeated' ? 1 : 0, $this->changes->list);
    }

    public function testMissingNotificationBehindAWarmMirrorRefusesTheReset(): void
    {
        $id = $this->failedDelivery();
        self::assertSame($this->notificationId, Hilos::$db->notifications[$this->notificationId]->id);
        Database::sql('DELETE FROM hilos_notification WHERE id = ?', [$this->notificationId]);
        self::assertTrue(isset(Hilos::$db->notifications[$this->notificationId]));

        $this->assertResetRefused($id, 'Delivery notification is unavailable');
        self::assertSame(DeliveryStatus::FAILED, Hilos::$db->notificationDeliveries[$id]->status);
    }

    /** The owner answers a vanished notification in the reset's own words, changes nothing and queues nothing. */
    public function testOwnerAnswersAMissingNotificationBehindAWarmMirrorWithoutChanges(): void
    {
        $id = $this->failedDelivery();
        $agent = new RetryOwnerMailAgent('1');
        $ask = $this->retryAsk($id);
        self::assertSame($this->notificationId, Hilos::$db->notifications[$this->notificationId]->id);
        Database::sql('DELETE FROM hilos_notification WHERE id = ?', [$this->notificationId]);
        self::assertTrue(isset(Hilos::$db->notifications[$this->notificationId]));
        $before = Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
        $this->changes->list = [];

        $this->deliverRetry($agent, $ask);

        $this->assertAnsweredOnce($ask, 'Delivery notification is unavailable');
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows());
        self::assertSame([], $this->changes->list);
        self::assertSame([], self::queued($agent));
        self::assertSame([], $agent->errors, 'An expected refusal is said, not logged');
    }

    /** @return iterable<string, array{bool}> What the shard still holds under the id when the row turns terminal elsewhere */
    public static function residualOperations(): iterable
    {
        yield 'a retry backoff' => [false];
        yield 'an attempt in flight' => [true];
    }

    /**
     * A row that turned terminal behind the shard's back leaves nothing of its old operation to the taken-back one.
     *
     * @param bool $inFlight Whether the shard still pumps an open attempt rather than waits out a backoff
     */
    #[DataProvider('residualOperations')]
    public function testTakenBackDeliveryStartsAfreshFromWhatTheShardStillHeld(bool $inFlight): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending($this->notificationId, MailDeliveryChannel::NAME, 0)->id;
        $agent = new RetryOwnerMailAgent('1');
        $agent->attemptCeiling = 2;
        $stale = new SettledRetryAttempt($inFlight ? null : 'mailbox full', busy: $inFlight);
        $agent->script = [$stale];
        $this->tick($agent);
        if (!$inFlight) {
            // The first failure leaves the row pending, and the second attempt waits out its backoff.
            $this->tick($agent);
            self::assertSame(DeliveryStatus::PENDING, Hilos::$db->notificationDeliveries[$id]->status);
        }
        self::assertSame(!$inFlight, $stale->closed);
        self::assertSame($inFlight ? [] : [$id], self::backingOff($agent));
        Database::sql(
            'UPDATE hilos_notification_delivery SET status = ?, attempts = 2, last_error = ? WHERE id = ?',
            [DeliveryStatus::FAILED, 'gave up elsewhere', $id],
        );
        $agent->script = [new SettledRetryAttempt(null)];
        $ask = $this->retryAsk($id);

        $this->deliverRetry($agent, $ask);

        $this->assertAnsweredOnce($ask, null);
        self::assertTrue($stale->closed);
        self::assertSame([$id], self::queued($agent));
        self::assertSame([], self::backingOff($agent), 'No old backoff is left in the way, however fast the next tick follows');
        $this->tick($agent);
        self::assertCount(2, $agent->started, 'The taken-back delivery starts on the next tick, with no old backoff or attempt in its way');
        $this->tick($agent);
        self::assertSame(
            [['status' => DeliveryStatus::SENT, 'attempts' => '1', 'last_error' => null]],
            Database::sql('SELECT status, attempts, last_error FROM hilos_notification_delivery WHERE id = ?', [$id])->rows(),
        );
    }

    /** @param int $id Delivery reset by the mail shard owner on behalf of an admin request */
    private function resetAsOwner(int $id): void
    {
        $this->claim(self::mailOwner());
        ExecutionContext::run(
            new ExecutionFrame(agentId: self::mailOwner(), acceptKey: self::ACCEPT_KEY, requestId: self::REQUEST_ID),
            static fn () => Hilos::$db->notificationDeliveries[$id]->actions->resetForRetry(),
        );
    }

    /**
     * @param int $id Delivery whose reset the owner must refuse
     * @param string $reason Sentence the refusal carries
     */
    private function assertResetRefused(int $id, string $reason): void
    {
        $before = Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
        $announced = count($this->changes->list);
        try {
            $this->resetAsOwner($id);
            self::fail('The reset must be refused');
        } catch (ValidationException $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows());
        self::assertCount($announced, $this->changes->list);
    }
}
