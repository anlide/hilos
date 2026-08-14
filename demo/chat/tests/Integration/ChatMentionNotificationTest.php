<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Database\Actions\Collection\EventsActions;
use Demo\Chat\Hilos;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Notification\NotificationSeverity;

/**
 * Proves a published message notifies the users it names (HIL-557).
 *
 * Detection sits in the model, so these cases drive the real write path -
 * {@see EventsActions::addMessage()} - rather than the notifier alone: that is the
 * single door both authors go through, and a mention raised anywhere else would be a
 * mention nobody receives.
 */
final class ChatMentionNotificationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** Distinctive enough that no leftover user of another case answers to it. */
    private const string TARGET_NAME = 'MentionTarget557';

    private const string AUTHOR_NAME = 'MentionAuthor557';

    protected function setUp(): void
    {
        parent::setUp();
        TruthSourceRegistry::register(HilosDbContext::notifications, true, self::TEST_AGENT_ID);
        $this->deleteChatNotifications();
        Hilos::$db->events->actions->deleteAll();
    }

    protected function tearDown(): void
    {
        $this->deleteChatNotifications();
        Hilos::$db->events->actions->deleteAll();
        parent::tearDown();
    }

    public function testMentionNotifiesTheNamedUser(): void
    {
        $author = Hilos::$db->users->actions->createWithName(self::AUTHOR_NAME);
        $target = Hilos::$db->users->actions->createWithName(self::TARGET_NAME);

        $event = Hilos::$db->events->actions->addMessage(
            'ping @' . self::TARGET_NAME . ', are you there?',
            userId: $author->id,
        );

        $notification = $this->onlyNotificationFor($target->id);

        self::assertSame(ChatNotificationType::MENTION, $notification->type);
        self::assertSame(NotificationSeverity::INFO, $notification->severity);
        self::assertSame(self::AUTHOR_NAME . ' mentioned you', $notification->title);
        self::assertSame('ping @' . self::TARGET_NAME . ', are you there?', $notification->body);
        self::assertSame(
            [
                'eventId' => (int)$event->id,
                'authorUserId' => $author->id,
                'authorBotId' => null,
                'excerpt' => 'ping @' . self::TARGET_NAME . ', are you there?',
            ],
            $notification->decodedData(),
        );
    }

    public function testAuthorNamingThemselvesIsNotNotified(): void
    {
        $author = Hilos::$db->users->actions->createWithName(self::AUTHOR_NAME);

        Hilos::$db->events->actions->addMessage(
            'note to @' . self::AUTHOR_NAME . ': buy milk',
            userId: $author->id,
        );

        self::assertCount(0, $this->notificationsFor($author->id));
    }

    public function testANameInsideALongerMentionIsNotAMention(): void
    {
        $author = Hilos::$db->users->actions->createWithName(self::AUTHOR_NAME);
        $shortName = Hilos::$db->users->actions->createWithName('Men557');

        // '@Men557ies' continues past the name, so the short name is not the one named.
        Hilos::$db->events->actions->addMessage('hello @Men557ies', userId: $author->id);

        self::assertCount(0, $this->notificationsFor($shortName->id));
    }

    public function testMentionMatchesRegardlessOfCase(): void
    {
        $author = Hilos::$db->users->actions->createWithName(self::AUTHOR_NAME);
        $target = Hilos::$db->users->actions->createWithName(self::TARGET_NAME);

        Hilos::$db->events->actions->addMessage(
            'hey @' . mb_strtolower(self::TARGET_NAME),
            userId: $author->id,
        );

        self::assertCount(1, $this->notificationsFor($target->id));
    }

    public function testAClosedAccountIsNotNotified(): void
    {
        $author = Hilos::$db->users->actions->createWithName(self::AUTHOR_NAME);
        $survivor = Hilos::$db->users->actions->createWithName('MentionSurvivor557');
        $closed = Hilos::$db->users->actions->createWithName(self::TARGET_NAME);
        // Tombstoning is the one way an account is closed here: it blocks the row and
        // points it at the survivor, so this covers both skips the notifier makes.
        $closed->actions->tombstone((int)$survivor->id);

        Hilos::$db->events->actions->addMessage(
            'anyone seen @' . self::TARGET_NAME . '?',
            userId: $author->id,
        );

        self::assertCount(0, $this->notificationsFor($closed->id));
    }

    public function testABotAuthorMentionsAUser(): void
    {
        $bot = Hilos::$db->bots->actions->create('MentionBot557');
        $target = Hilos::$db->users->actions->createWithName(self::TARGET_NAME);

        $event = Hilos::$db->events->actions->addMessage(
            'welcome back @' . self::TARGET_NAME,
            botId: $bot->id,
        );

        $notification = $this->onlyNotificationFor($target->id);

        self::assertSame('MentionBot557 mentioned you', $notification->title);
        self::assertSame(
            [
                'eventId' => (int)$event->id,
                'authorUserId' => null,
                'authorBotId' => $bot->id,
                'excerpt' => 'welcome back @' . self::TARGET_NAME,
            ],
            $notification->decodedData(),
        );
    }

    public function testNamingTheSameUserTwiceNotifiesOnce(): void
    {
        $author = Hilos::$db->users->actions->createWithName(self::AUTHOR_NAME);
        $target = Hilos::$db->users->actions->createWithName(self::TARGET_NAME);

        Hilos::$db->events->actions->addMessage(
            '@' . self::TARGET_NAME . ' and again @' . self::TARGET_NAME,
            userId: $author->id,
        );

        self::assertCount(1, $this->notificationsFor($target->id));
    }

    public function testALongMessageIsCutToAnExcerpt(): void
    {
        $author = Hilos::$db->users->actions->createWithName(self::AUTHOR_NAME);
        $target = Hilos::$db->users->actions->createWithName(self::TARGET_NAME);
        $message = '@' . self::TARGET_NAME . ' ' . str_repeat('a', 200);

        Hilos::$db->events->actions->addMessage($message, userId: $author->id);

        $notification = $this->onlyNotificationFor($target->id);

        self::assertSame(mb_substr($message, 0, 140) . '…', $notification->body);
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
    private function deleteChatNotifications(): void
    {
        Database::sql(
            'DELETE FROM `' . EntityNotification::_table . '`'
            . ' WHERE `' . EntityNotification::type . '` LIKE \'chat.%\'',
        );
    }
}
