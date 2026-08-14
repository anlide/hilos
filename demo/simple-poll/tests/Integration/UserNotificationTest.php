<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Integration;

use Demo\SimplePoll\Agents\Hilos\DemoHilosAgent;
use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Constants\PollNotificationType;
use Demo\SimplePoll\Database\View\Item\User;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Pages\Hilos\Users\UserPage;
use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Demo\SimplePoll\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Notification\NotificationSeverity;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Proves this demo raises the two account-level notifications it has (HIL-557).
 *
 * There is no domain content here yet, so the events worth telling somebody about
 * are the ones the demo really produces: a visitor's session creates an account,
 * and an administrator renames one. Both go through the durable notifier, so these
 * cases read the rows back rather than watch a signal.
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

    public function testARegisteringVisitorNotifiesTheAdministrators(): void
    {
        $admin = Hilos::$db->users->actions->register(RandomHelper::hex(16));
        $admin->actions->setAdmin(true);
        $bystander = Hilos::$db->users->actions->register(RandomHelper::hex(16));

        $newcomer = $this->handshake(RandomHelper::hex(16));
        $notification = $this->onlyNotificationFor($admin->id);

        self::assertSame(PollNotificationType::USER_REGISTERED, $notification->type);
        self::assertSame(NotificationSeverity::INFO, $notification->severity);
        self::assertSame('New user joined: ' . $newcomer->name, $notification->title);
        self::assertNull($notification->body);
        self::assertSame(
            ['userId' => $newcomer->id, 'userName' => $newcomer->name],
            $notification->decodedData(),
        );

        // Everybody else is left alone, the newcomer included.
        self::assertCount(0, $this->notificationsFor($bystander->id));
        self::assertCount(0, $this->notificationsFor($newcomer->id));
    }

    public function testAReturningVisitorNotifiesNobody(): void
    {
        $admin = Hilos::$db->users->actions->register(RandomHelper::hex(16));
        $admin->actions->setAdmin(true);
        $sessionToken = RandomHelper::hex(16);
        Hilos::$db->users->actions->register($sessionToken);

        $this->handshake($sessionToken);

        self::assertCount(0, $this->notificationsFor($admin->id));
    }

    public function testARenamedUserIsToldWhoTheyAreNow(): void
    {
        $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
        $userId = (int)$user->id;
        $oldName = $user->name;

        new UserPage(new DemoHilosAgent())->onAction(
            'poll-notify-ak',
            HilosSignalConstants::HILOS_USER_UPDATE,
            new HilosUserUpdateActionDTO($userId, 'Renamed by admin'),
        );

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
     * Drives one handshake, the way the daemon does once it has resolved the token.
     *
     * @param string $sessionToken Session token the handshake carries
     * @return User User the handshake resolved or created
     */
    private function handshake(string $sessionToken): User
    {
        new PollAgent()->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'poll-notify-handshake',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
                sessionToken: $sessionToken,
            ),
            '',
            '',
        );

        $user = Hilos::$db->users->findBySession($sessionToken);
        self::assertNotNull($user);

        return $user;
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
