<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\UserRenameFeedLinkSignalData;
use Demo\Chat\Database\View\Item\UserRename;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Log\AgentLogStream;
use Hilos\Utils\Logger;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
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
 * (HIL-1404); on that answer chat's hook writes the feed event and asks the person's agent to
 * link the journal row to it without an answer (HIL-1896).
 * The event's type follows the row's author, and a person renamed by somebody else is told so.
 * Clearing the room's history asks each person's agent to take its rows off, and keeps them (HIL-1897).
 */
final class RenameFeedTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** Connection an administrator submits a rename from. */
    private const string ADMIN_ACCEPT_KEY = 'rename-feed-admin-ak';

    /** Connection a person asks to be renamed from. */
    private const string SELF_ACCEPT_KEY = 'rename-feed-self-ak';

    private string $logDirectory;

    /** Starts the feed fixture with no live connections. */
    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->logDirectory = sys_get_temp_dir() . '/hilos-rename-feed-' . bin2hex(random_bytes(6));
        mkdir($this->logDirectory);
        Logger::setLogFile($this->logDirectory . '/main.log');
    }

    /** Drops chat connections before the base releases the name protocol owners. */
    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAcceptKey(null);
        Hilos::$rt->connections->actions->clear();
        try {
            parent::tearDown();
        } finally {
            Logger::resetLogFile();
            foreach (array_diff(scandir($this->logDirectory), ['.', '..']) as $entry) {
                unlink($this->logDirectory . '/' . $entry);
            }
            rmdir($this->logDirectory);
        }
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
        self::assertCount(0, Hilos::$rt->hilosModerationChecks, 'An administrator rename bypasses moderation');
        self::assertSame('After admin', Hilos::$db->users[$userId]->name);
        $row = $this->onlyJournalRowOf($userId);
        self::assertSame($adminId, $row->renamed_by_user_id);
        self::assertSame('Before admin', $row->old_name);
        self::assertSame('After admin', $row->new_name);
        self::assertNotNull($row->event_id, 'The journal row is linked to its feed line');
        self::assertSame(ChatEventType::USER_RENAMED_BY_ADMIN->value, Hilos::$db->events[$row->event_id]->type);
        self::assertSame($row->id, Hilos::$db->events[$row->event_id]->userRename?->id);

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

        self::assertSame('After self', Hilos::$db->users[$userId]->name);
        $row = $this->onlyJournalRowOf($userId);
        self::assertSame($userId, $row->renamed_by_user_id);
        self::assertSame('Before self', $row->old_name);
        self::assertSame('After self', $row->new_name);
        self::assertNotNull($row->event_id);
        self::assertSame(ChatEventType::USER_RENAMED->value, Hilos::$db->events[$row->event_id]->type);
        self::assertCount(0, $this->notificationsFor($userId), 'A rename of oneself tells nobody');
    }

    /**
     * An equal name completes at admission without a check, journal row or feed event.
     *
     * @throws HilosException When seeding or admission fails
     */
    public function testAnAllowedRenameToTheSameNameWritesNothing(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Same name')->id;
        $events = count(Hilos::$db->events);

        $change = $this->submitProfileNameChange($userId, self::SELF_ACCEPT_KEY, 'Same name');

        self::assertSame(StateHilosProfileNameChange::PHASE_DONE, $change->phase);
        $this->assertProfileNamePhase(self::SELF_ACCEPT_KEY, StateHilosProfileNameChange::PHASE_DONE);
        self::assertNull($change->refusal);
        self::assertNull($change->deadlineAt);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::SELF_ACCEPT_KEY, ModerationKindKey::PROFILE_NAME));
        self::assertSame('Same name', Hilos::$db->users[$userId]->name);
        self::assertCount(0, $this->notificationsFor($userId));

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
        self::assertSame(ChatEventType::USER_RENAMED->value, Hilos::$db->events[$row->event_id]->type);
        self::assertCount(0, $this->notificationsFor($adminId));
    }

    /** A frame arriving after history cleanup silently leaves the journal unlinked. */
    public function testALateFeedLinkDoesNotReviveADeletedEvent(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Late feed')->id;
        $row = Hilos::$db->userRenames->actions->add($userId, $userId, 'Before late', 'Late feed');
        $link = $this->queuedFeedLink($row);
        self::assertNull($row->eventId, 'The hook creates only the event');

        Hilos::$db->events->actions->deleteAll();
        $this->deliverFeedLink($userId, $link);

        self::assertNull($row->eventId);
        self::assertNull($this->onlyJournalRowOf($userId)->event_id);
        self::assertFileDoesNotExist($this->personErrorLog($userId));
        $this->assertNoAgentReplies();
    }

    /** A repeated frame keeps one journal link and writes no refusal. */
    public function testARepeatedFeedLinkKeepsTheOriginalEvent(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Repeated feed')->id;
        $row = Hilos::$db->userRenames->actions->add($userId, $userId, 'Before repeat', 'Repeated feed');
        $link = $this->queuedFeedLink($row);

        $this->deliverFeedLink($userId, $link);
        $this->deliverFeedLink($userId, $link);

        self::assertSame($link->eventId, $row->eventId);
        self::assertSame($link->eventId, $this->onlyJournalRowOf($userId)->event_id);
        self::assertCount(1, EntityUserRename::get([EntityUserRename::event_id => $link->eventId]));
        self::assertFalse($this->personAgent($userId)->shouldStop());
        self::assertFileDoesNotExist($this->personErrorLog($userId));
        $this->assertNoAgentReplies();
    }

    /** A unique-key refusal is contained and restores the same loaded row's memory. */
    public function testARefusedLinkRollsBackTheDatabaseAndTheLoadedRow(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Rollback feed')->id;
        $first = Hilos::$db->userRenames->actions->add($userId, $userId, 'First before', 'First after');
        $link = $this->queuedFeedLink($first);
        $this->deliverFeedLink($userId, $link);
        $second = Hilos::$db->userRenames->actions->add($userId, $userId, 'Second before', 'Second after');

        $this->deliverFeedLink($userId, new UserRenameFeedLinkSignalData($userId, (int)$second->id, $link->eventId));

        self::assertNull($second->eventId, 'The existing View reference observes the rollback');
        self::assertNull(Hilos::$db->userRenames[(int)$second->id]->eventId);
        $storedSecond = EntityUserRename::get([EntityUserRename::id => $second->id])->first();
        self::assertNotNull($storedSecond);
        self::assertNull($storedSecond->event_id);
        self::assertSame($link->eventId, $first->eventId);
        self::assertCount(1, EntityUserRename::get([EntityUserRename::event_id => $link->eventId]));
        $error = (string)file_get_contents($this->personErrorLog($userId));
        self::assertStringContainsString("user #{$userId}, rename #{$second->id}, event #{$link->eventId}", $error);
        self::assertStringContainsString('not written:', $error);
        self::assertStringNotContainsString('Second before', $error);
        self::assertStringNotContainsString('Second after', $error);
    }

    /** A folded person's service frame still links the row, then stops the person agent. */
    public function testAFoldedPersonsFeedLinkIsWrittenBeforeTheAgentStops(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Folded feed')->id;
        $survivorId = (int)Hilos::$db->users->actions->createWithName('Feed survivor')->id;
        $row = Hilos::$db->userRenames->actions->add($userId, $userId, 'Before fold', 'Folded feed');
        $link = $this->queuedFeedLink($row);
        Hilos::$db->userMerges->actions->add($userId, $survivorId);

        $this->deliverFeedLink($userId, $link);

        self::assertSame($link->eventId, $row->eventId);
        self::assertSame($link->eventId, $this->onlyJournalRowOf($userId)->event_id);
        self::assertTrue($this->personAgent($userId)->shouldStop());
        self::assertFileDoesNotExist($this->personErrorLog($userId));
    }

    /** Set ownership logs a foreign row; a foreign addressee is an agent protocol error. */
    public function testAnotherPersonsRowAndAnotherAddresseeAreRefused(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Feed owner')->id;
        $otherId = (int)Hilos::$db->users->actions->createWithName('Other feed owner')->id;
        $otherRow = Hilos::$db->userRenames->actions->add($otherId, $otherId, 'Other before', 'Other after');
        $link = $this->queuedFeedLink($otherRow);

        $this->deliverFeedLink($userId, new UserRenameFeedLinkSignalData($userId, $link->renameId, $link->eventId));

        self::assertNull($otherRow->eventId);
        self::assertNull($this->onlyJournalRowOf($otherId)->event_id);
        $error = (string)file_get_contents($this->personErrorLog($userId));
        self::assertStringContainsString("user #{$userId}, rename #{$link->renameId}, event #{$link->eventId}", $error);
        self::assertStringContainsString('not written:', $error);
        self::assertStringNotContainsString('Other before', $error);
        self::assertStringNotContainsString('Other after', $error);

        $this->expectException(AgentException::class);
        $this->deliverFeedLink($userId, $link);
    }

    /** The library can create the feed event, but cannot edit the person's journal anymore. */
    public function testTheUsersLibraryCannotWriteTheFeedLink(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Library feed')->id;
        $row = Hilos::$db->userRenames->actions->add($userId, $userId, 'Before library', 'Library feed');
        $link = $this->queuedFeedLink($row);
        $library = $this->usersLibrary();

        $this->expectException(WriteNotAllowedException::class);
        $this->underAgent($library, static fn () => $row->actions->linkEvent($link->eventId));
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
        $oldName = Hilos::$db->users[$userId]->name;
        $eventsBefore = count(Hilos::$db->events);
        $check = $this->beginProfileNameCheck($userId, self::SELF_ACCEPT_KEY, $name);
        self::assertCount(0, EntityUserRename::get([EntityUserRename::user_id => $userId]));

        $signal = new AgentSignalData(new ModerationVerdictSignalData(
            acceptKey: self::SELF_ACCEPT_KEY,
            kind: ModerationKindKey::PROFILE_NAME,
            requestId: $check->requestId,
            allow: true,
            reason: 'ok',
        ));
        ExecutionContext::setCurrentAcceptKey($signal->getAcceptKey());
        try {
            $library = $this->usersLibrary();
            $this->underAgent($library, static fn () => $library->onSignalAgent($signal, '', HilosSignalConstants::HILOS_MODERATION_VERDICT));
            self::assertSame($oldName, Hilos::$db->users[$userId]->name, 'The library only asks the person owner');
            self::assertCount($eventsBefore, Hilos::$db->events);
            self::assertSame(3, $this->deliverPersonAgentFrames(), 'One owner ask, its reply and the feed link');
            $this->assertProfileNamePhase(self::SELF_ACCEPT_KEY, StateHilosProfileNameChange::PHASE_DONE);
            $expectedEvents = $eventsBefore + 1;
            self::assertCount($expectedEvents, Hilos::$db->events, 'Exactly one feed line per changed name');
            $this->underAgent($library, static fn () => $library->onSignalAgent($signal, '', HilosSignalConstants::HILOS_MODERATION_VERDICT));
            self::assertSame(0, $this->deliverPersonAgentFrames(), 'A repeated verdict sends no second owner ask');
            self::assertCount($expectedEvents, Hilos::$db->events);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }

    /**
     * Runs the real project hook but holds its link frame for an explicit delivery.
     *
     * @param UserRename $row Journal row whose event the library creates
     * @return UserRenameFeedLinkSignalData The one queued link, without an answer address
     */
    private function queuedFeedLink(UserRename $row): UserRenameFeedLinkSignalData
    {
        $library = $this->usersLibrary();
        $this->underAgent($library, static fn () => $library->afterUserRenamed($row));
        $links = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === ChatSignalConstants::USER_RENAME_FEED_LINK) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(UserRenameFeedLinkSignalData::class, $signal->data->data);
                $links[] = $signal->data->data;
            }
        }
        self::assertCount(1, $links);
        self::assertSame($row->userId, $links[0]->userId);
        self::assertSame($row->id, $links[0]->renameId);

        return $links[0];
    }

    /**
     * @param int $userId Person agent receiving the frame
     * @param UserRenameFeedLinkSignalData $link Captured link payload
     */
    private function deliverFeedLink(int $userId, UserRenameFeedLinkSignalData $link): void
    {
        $agent = $this->personAgent($userId);
        $this->underAgent($agent, static fn () => $agent->onSignalAgent(
            new AgentSignalData($link),
            '',
            ChatSignalConstants::USER_RENAME_FEED_LINK,
        ));
    }

    /** DB syncs carry writes; the link protocol itself sends no answer. */
    private function assertNoAgentReplies(): void
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotInstanceOf(AgentSignalData::class, $signal->data);
        }
    }

    /**
     * @param int $userId Person agent whose errors are captured
     * @return string Error stream path
     */
    private function personErrorLog(int $userId): string
    {
        return AgentLogStream::pathFor($this->logDirectory, $this->personAgent($userId)->getId(), true);
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
