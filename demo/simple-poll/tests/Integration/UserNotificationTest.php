<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Integration;

use Demo\SimplePoll\Agents\Hilos\DemoHilosAgent;
use Demo\SimplePoll\Constants\PollNotificationType;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Pages\Hilos\Users\UserPage;
use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Demo\SimplePoll\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\HilosException;
use Hilos\Notification\NotificationSeverity;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Proves this demo raises the account-level notification it has (HIL-557).
 *
 * There is no domain content here yet, so the one event worth telling somebody
 * about is the one the demo really produces: an administrator renames an account.
 * The visitor-registered notification went with the visitor's user row (HIL-611) -
 * a notification is addressed to a user id, and a guest has none. It goes through
 * the durable notifier, so this case reads the row back rather than watch a signal.
 *
 * Requires the test DB reset (composer run test:db-reset).
 */
final class UserNotificationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        TruthSourceRegistry::register(HilosDbContext::notifications, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(PollRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        $this->deleteDemoNotifications();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt->connections->actions->clear();
        $this->deleteDemoNotifications();
        parent::tearDown();
    }

    public function testARenamedUserIsToldWhoTheyAreNow(): void
    {
        $user = Hilos::$db->users->actions->registerAdmin();
        $userId = (int)$user->id;
        $oldName = $user->name;

        new UserPage(new DemoHilosAgent())->onAction(
            'poll-notify-ak',
            HilosSignalConstants::HILOS_USER_UPDATE,
            new HilosUserUpdateActionDTO($userId, 'Renamed by admin'),
        );
        $this->deliverHilosLibraryFrames();

        $notification = $this->onlyNotificationFor($userId);

        self::assertSame(PollNotificationType::USER_RENAMED, $notification->type);
        self::assertSame(NotificationSeverity::INFO, $notification->severity);
        self::assertSame('An administrator renamed your account', $notification->title);
        self::assertSame('Your name is now Renamed by admin', $notification->body);
        self::assertSame(
            ['oldName' => $oldName, 'newName' => 'Renamed by admin', 'actorUserId' => null],
            $notification->decodedData(),
        );
    }

    /**
     * Asserts the recipient received exactly one notification and returns it.
     *
     * @param ?int $userId Recipient user id
     * @return ObjectNotification The recipient's only notification
     */
    private function onlyNotificationFor(?int $userId): ObjectNotification
    {
        $notifications = $this->notificationsFor($userId);
        self::assertCount(1, $notifications);

        return $notifications[0];
    }

    /**
     * Lists the notifications currently held for the recipient.
     *
     * @param ?int $userId Recipient user id
     * @return list<ObjectNotification> Recipient notifications, newest first
     */
    private function notificationsFor(?int $userId): array
    {
        self::assertNotNull($userId);
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::notifications);
        self::assertInstanceOf(ObjectNotifications::class, $collection);

        return $collection->listForUser($userId, 20);
    }

    /**
     * Drops every notification this demo raises, so each case starts from a known count.
     */
    private function deleteDemoNotifications(): void
    {
        Database::sql(
            'DELETE FROM `' . EntityNotification::_table . '`'
            . ' WHERE `' . EntityNotification::type . '` LIKE \'poll.%\'',
        );
    }
}
