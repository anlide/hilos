<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Core\Router\PollsSignalRouter;
use Demo\Polls\Database\Database;
use Demo\Polls\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationPreference as EntityNotificationPreference;
use Hilos\Database\Entity\Item\PushSubscription as EntityPushSubscription;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * An account erasure in a demo that delivers no notifications (HIL-1296), run by the session
 * holder's sweep.
 *
 * Push subscriptions come with the framework's notifications since HIL-1296, so this demo carries
 * their table and erases them the way the chat does: the person's notification, channel setting
 * and push subscription go in the erasure's transaction, and the neighbour's stay. The
 * subscription is written by the same call the push_subscribe action makes, which is the proof
 * that subscribing here stores a row.
 *
 * Driven inside the library's own execution frame, as the chat's erasure case drives it: every
 * write runs as the agent that owns the sessions, so the borrowed claims are asked exactly as on
 * a live node.
 *
 * Requires the test DB reset before run (composer run test:db-reset).
 */
final class AccountErasureTest extends IntegrationTestCase
{
    /** A moment long gone, so the request is due on the first tick. */
    private const string PAST = '2026-01-01 00:00:00';

    /**
     * Gives the case a signal router, which the sign-outs and the notifications frame are queued on.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new PollsSignalRouter());
    }

    /**
     * The person's notification, channel setting and push subscription go, the neighbour's stay.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testTheErasureDeletesThePersonsNotificationRows(): void
    {
        $personId = (int)Hilos::$db->users->actions->createWithName('Leaving')->id;
        $otherId = (int)Hilos::$db->users->actions->createWithName('Staying')->id;
        foreach ([$personId, $otherId] as $userId) {
            self::seedNotificationRows($userId);
        }
        Database::sqlRun(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`) VALUES (?, ?, ?)',
            [$personId, self::PAST, self::PAST],
        );

        $this->drainSignals();
        $this->runErasure();

        self::assertCount(0, EntityUser::get([EntityUser::id => $personId]), 'The person row is gone');
        self::assertCount(0, EntityNotification::get([EntityNotification::user_id => $personId]));
        self::assertCount(0, EntityNotificationPreference::get([EntityNotificationPreference::user_id => $personId]));
        self::assertCount(0, EntityPushSubscription::get([EntityPushSubscription::user_id => $personId]));
        self::assertCount(1, EntityUser::get([EntityUser::id => $otherId]));
        self::assertCount(1, EntityNotification::get([EntityNotification::user_id => $otherId]));
        self::assertCount(1, EntityNotificationPreference::get([EntityNotificationPreference::user_id => $otherId]));
        self::assertCount(1, EntityPushSubscription::get([EntityPushSubscription::user_id => $otherId]));

        Database::sql('SELECT `completed_at` FROM `hilos_account_deletion` WHERE `user_id` = ?', [$personId]);
        self::assertNotNull(Database::row()['completed_at'] ?? null, 'The erasure did not fail: the request stays behind, carried out');
    }

    /**
     * @param int $userId Person given one notification, one channel setting and one push subscription
     * @throws HilosException When a row cannot be written
     */
    private static function seedNotificationRows(int $userId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`) VALUES (?, ?, ?)',
            [$userId, 'account.test', 'Test notification'],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_notification_preference` (`user_id`, `channel`) VALUES (?, ?)',
            [$userId, 'email'],
        );
        Hilos::$db->pushSubscriptions->actions->subscribe(
            $userId,
            'https://push.example.test/' . RandomHelper::hex(8),
            'key',
            'secret',
            null,
        );
    }

    /**
     * Arms and runs the sessions library's tick inside its own execution frame.
     *
     * @throws HilosException When the tick fails
     */
    private function runErasure(): void
    {
        $library = $this->sessionsLibrary();
        ExecutionContext::run(
            new ExecutionFrame(agentId: $library->getId()),
            static function () use ($library): void {
                $library->onTick();
            },
        );
    }
}
