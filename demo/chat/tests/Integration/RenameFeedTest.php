<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\HilosException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\AdminRenameSignalData;
use Hilos\Users\UserNotificationType;

/**
 * Chat renames through the framework's journal and writes each rename into its feed (HIL-1196).
 *
 * Both ways a chat person is renamed end in the framework's rename ask: an administrator's frame
 * is the framework library's to hand on, and a moderated rename of oneself asks once the moderator
 * allowed the name. The person's agent writes the name and the journal row and answers the library
 * (HIL-1404); on that answer chat's hook writes the feed event and links the journal row to it.
 * The event's type follows the row's author, and a person renamed by somebody else is told so.
 * Clearing the room's history takes the rows off their events and keeps them.
 */
final class RenameFeedTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** Connection an administrator submits a rename from. */
    private const string ADMIN_ACCEPT_KEY = 'rename-feed-admin-ak';

    /** Connection a person asks to be renamed from. */
    private const string SELF_ACCEPT_KEY = 'rename-feed-self-ak';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::initSignalRouter(new ChatSignalRouter());
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAcceptKey(null);
        Hilos::$rt->connections->actions->clear();
        parent::tearDown();
    }

    /**
     * An administrator's rename is the framework's: the name, a journal row by the administrator,
     * the feed line 'renamed by admin' linked to it, and the notice to the renamed person.
     *
     * @throws HilosException When seeding or the rename fails
     */
    public function testAnAdministratorsRenameReachesTheFeedAndTheRenamedPerson(): void
    {
        $adminId = (int)Hilos::$db->users->actions->createWithName('Feed admin')->id;
        $userId = (int)Hilos::$db->users->actions->createWithName('Before admin')->id;

        $answer = $this->askAdminRename($userId, 'After admin', $adminId);

        self::assertNull($answer->error);
        self::assertSame('After admin', Hilos::$db->users[$userId]?->name);
        $row = $this->onlyJournalRowOf($userId);
        self::assertSame($adminId, $row->renamed_by_user_id);
        self::assertSame('Before admin', $row->old_name);
        self::assertSame('After admin', $row->new_name);
        self::assertNotNull($row->event_id, 'The journal row is linked to its feed line');
        self::assertSame(ChatEventType::USER_RENAMED_BY_ADMIN->value, Hilos::$db->events[$row->event_id]?->type);
        self::assertSame($row->id, Hilos::$db->events[$row->event_id]?->userRename?->id);

        $notices = $this->notificationsFor($userId);
        self::assertCount(1, $notices);
        self::assertSame(UserNotificationType::RENAMED, $notices[0]->type);
        self::assertSame(
            ['oldName' => 'Before admin', 'newName' => 'After admin', 'actorUserId' => $adminId],
            $notices[0]->decodedData(),
        );
    }

    /**
     * A name the moderator allowed is the person's own rename: the row names the person as its
     * author, the feed says 'renamed', and nobody is notified.
     *
     * @throws HilosException When seeding or the rename fails
     */
    public function testAnAllowedRenameOfOneselfIsTheFeedsPlainRename(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Before self')->id;

        $this->allowRename($userId, 'After self');

        self::assertSame('After self', Hilos::$db->users[$userId]?->name);
        $row = $this->onlyJournalRowOf($userId);
        self::assertSame($userId, $row->renamed_by_user_id);
        self::assertNotNull($row->event_id);
        self::assertSame(ChatEventType::USER_RENAMED->value, Hilos::$db->events[$row->event_id]?->type);
        self::assertCount(0, $this->notificationsFor($userId), 'A rename of oneself tells nobody');
    }

    /**
     * An allowed name the person already carries writes nothing: no journal row, no feed line.
     *
     * @throws HilosException When seeding or the verdict fails
     */
    public function testAnAllowedRenameToTheSameNameWritesNothing(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Same name')->id;
        $events = count(Hilos::$db->events);

        $this->allowRename($userId, 'Same name');

        self::assertCount(0, EntityUserRename::get([EntityUserRename::user_id => $userId]));
        self::assertCount($events, Hilos::$db->events, 'No feed line for a rename that did not happen');
    }

    /**
     * An administrator renaming their own account renames themselves: the feed says 'renamed'.
     *
     * @throws HilosException When seeding or the rename fails
     */
    public function testAnAdministratorRenamingThemselvesIsThePlainRename(): void
    {
        $adminId = (int)Hilos::$db->users->actions->createWithName('Admin before')->id;

        $this->askAdminRename($adminId, 'Admin after', $adminId);

        $row = $this->onlyJournalRowOf($adminId);
        self::assertSame($adminId, $row->renamed_by_user_id);
        self::assertNotNull($row->event_id);
        self::assertSame(ChatEventType::USER_RENAMED->value, Hilos::$db->events[$row->event_id]?->type);
        self::assertCount(0, $this->notificationsFor($adminId));
    }

    /**
     * Clearing the room's history keeps the journal - it is the person's history - and takes each
     * row off the event that went.
     *
     * @throws HilosException When seeding or the cleanup fails
     */
    public function testClearingTheHistoryKeepsTheJournalOffItsEvents(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Before clear')->id;
        $this->allowRename($userId, 'After clear');
        $rowId = (int)$this->onlyJournalRowOf($userId)->id;

        Hilos::$db->events->actions->deleteAll();

        $row = $this->onlyJournalRowOf($userId);
        self::assertSame($rowId, $row->id);
        self::assertNull($row->event_id, 'The row lost its event in the database');
        self::assertNull(Hilos::$db->userRenames[$rowId]?->eventId, 'and in the cache that heard it');
        self::assertSame('After clear', Hilos::$db->userRenames[$rowId]?->newName);
    }

    /**
     * Hands the users library the rename the framework user card forwards, and returns its answer.
     *
     * @param int $userId Person to rename
     * @param string $name Name the administrator typed
     * @param int $adminUserId Administrator behind the submit
     * @return HandoverAnswerSignalData The one answer the library addressed to the card
     * @throws HilosException When the frame handler fails
     */
    private function askAdminRename(int $userId, string $name, int $adminUserId): HandoverAnswerSignalData
    {
        $this->usersLibrary()->onSignalAgent(
            new AgentSignalData(data: new AdminRenameSignalData(
                userId: $userId,
                name: $name,
                replySignal: HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
                acceptKey: self::ADMIN_ACCEPT_KEY,
                requestId: null,
                action: HilosSignalConstants::HILOS_USER_UPDATE,
                successMessage: null,
                adminUserId: $adminUserId,
            )),
            '',
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME,
        );
        $this->deliverPersonAgentFrames();
        $this->deliverNotificationFrames();

        $answers = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE
                && $signal->data instanceof AgentSignalData
                && $signal->data->data instanceof HandoverAnswerSignalData) {
                $answers[] = $signal->data->data;
            }
        }
        self::assertCount(1, $answers);

        return $answers[0];
    }

    /**
     * Runs a person's own rename to the moderator's allowing verdict.
     *
     * @param int $userId Person asking to be renamed
     * @param string $name Name the moderator allows
     * @throws HilosException When the verdict cannot be applied
     */
    private function allowRename(int $userId, string $name): void
    {
        Hilos::$rt->connections->actions->register(self::SELF_ACCEPT_KEY, $userId);
        Hilos::$rt->connections[self::SELF_ACCEPT_KEY]?->actions->startRenameModeration($name);

        $signal = new AgentSignalData(new RenameModerationResultSignalData(
            acceptKey: self::SELF_ACCEPT_KEY,
            userId: $userId,
            newName: $name,
            allow: true,
            reason: '',
        ));
        ExecutionContext::setCurrentAcceptKey($signal->getAcceptKey());
        try {
            $this->usersLibrary()->onSignalAgent($signal, '', ChatSignalConstants::RENAME_MODERATION_RESULT);
            $this->deliverPersonAgentFrames();
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }

    /**
     * @param int $userId Renamed person
     * @return EntityUserRename The person's only journal row, read from the database
     * @throws HilosException When the journal cannot be read
     */
    private function onlyJournalRowOf(int $userId): EntityUserRename
    {
        $rows = EntityUserRename::get([EntityUserRename::user_id => $userId]);
        self::assertCount(1, $rows);
        $row = $rows->first();
        self::assertInstanceOf(EntityUserRename::class, $row);

        return $row;
    }

    /**
     * Lists the notifications held for a person, once every pending emit is written.
     *
     * @param int $userId Recipient user id
     * @return list<ObjectNotification> Recipient notifications, newest first
     * @throws HilosException When an emitted notification cannot be written
     */
    private function notificationsFor(int $userId): array
    {
        $this->deliverNotificationFrames();
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::notifications);
        self::assertInstanceOf(ObjectNotifications::class, $collection);

        return $collection->listForUser($userId, 20);
    }
}
