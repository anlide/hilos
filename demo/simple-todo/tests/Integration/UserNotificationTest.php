<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tests\Integration;

use Demo\SimpleTodo\Agents\Hilos\DemoHilosAgent;
use Demo\SimpleTodo\Agents\TodoAgent;
use Demo\SimpleTodo\Constants\TodoNotificationType;
use Demo\SimpleTodo\Database\View\Item\User;
use Demo\SimpleTodo\Hilos;
use Demo\SimpleTodo\Pages\Hilos\Users\UserPage;
use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Demo\SimpleTodo\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\HilosException;
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
        RtTruthSourceRegistry::register(TodoRtContext::connections, true, self::TEST_AGENT_ID);
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
        $admin = Hilos::$db->users->actions->registerGuest();
        $admin->actions->setAdmin(true);
        $bystander = Hilos::$db->users->actions->registerGuest();

        $newcomer = $this->handshake(RandomHelper::hex(16));
        $notification = $this->onlyNotificationFor($admin->id);

        self::assertSame(TodoNotificationType::USER_REGISTERED, $notification->type);
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
        $admin = Hilos::$db->users->actions->registerGuest();
        $admin->actions->setAdmin(true);
        $sessionToken = $this->sessionOfReturningVisitor();

        $this->handshake($sessionToken);

        self::assertCount(0, $this->notificationsFor($admin->id));
    }

    public function testARenamedUserIsToldWhoTheyAreNow(): void
    {
        $user = Hilos::$db->users->actions->registerGuest();
        $userId = (int)$user->id;
        $oldName = $user->name;

        new UserPage(new DemoHilosAgent())->onAction(
            'todo-notify-ak',
            HilosSignalConstants::HILOS_USER_UPDATE,
            new HilosUserUpdateActionDTO($userId, 'Renamed by admin'),
        );

        $notification = $this->onlyNotificationFor($userId);

        self::assertSame(TodoNotificationType::USER_RENAMED, $notification->type);
        self::assertSame(NotificationSeverity::INFO, $notification->severity);
        self::assertSame('An administrator renamed your account', $notification->title);
        self::assertSame('Your name is now Renamed by admin', $notification->body);
        self::assertSame(
            ['oldName' => $oldName, 'newName' => 'Renamed by admin', 'actorUserId' => null],
            $notification->decodedData(),
        );
    }

    /**
     * Seeds the session a visitor comes back with: one already bound to a user.
     *
     * That is what "returning" means since HIL-407 - not a user row carrying a
     * token, but a session row carrying a user. The handshake driven on this token
     * therefore finds an identity and registers nobody.
     *
     * @return string Session token of the seeded returning visitor
     * @throws HilosException On database failure while seeding
     */
    private function sessionOfReturningVisitor(): string
    {
        $sessionToken = RandomHelper::hex(16);
        $returning = Hilos::$db->users->actions->registerGuest();

        Hilos::$db->sessions->actions->createAnonymous($sessionToken);
        Hilos::$db->sessions->findByToken($sessionToken)?->actions->bindUser((int)$returning->id);

        return $sessionToken;
    }

    /**
     * Drives one handshake, the way the daemon does once it has resolved the token.
     *
     * @param string $sessionToken Session token the handshake carries
     * @return User User the handshake resolved or created
     */
    private function handshake(string $sessionToken): User
    {
        new TodoAgent()->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'todo-notify-handshake',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
                sessionToken: $sessionToken,
            ),
            '',
            '',
        );

        $session = Hilos::$db->sessions->findByToken($sessionToken);
        self::assertNotNull($session);

        $userId = $session->userId;
        self::assertNotNull($userId, 'The handshake left the session without a user');

        $user = Hilos::$db->users[$userId] ?? null;
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
            . ' WHERE `' . EntityNotification::type . '` LIKE \'todo.%\'',
        );
    }
}
