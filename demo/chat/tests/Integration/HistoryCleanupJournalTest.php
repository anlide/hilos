<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\UserAgent;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkSignalData;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkedSignalData;
use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Demo\Chat\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\Chat\Database\View\Item\UserRename;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\HilosException;
use Hilos\Log\AgentLogStream;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use ReflectionProperty;

/** History deletion waits for every personal journal owner; all frames and cron passes are explicit. */
final class HistoryCleanupJournalTest extends IntegrationTestCase
{
    private ChatAgent $chat;
    private string $logDirectory;

    /** @var array<int, UserAgent> Personal owners whose claims this case releases */
    private array $owners = [];

    /** @var list<int> Registry fixture rows, released after events */
    private array $fileIds = [];

    /** Starts one history coordinator for the whole case, with an empty room. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        $this->logDirectory = sys_get_temp_dir() . '/hilos-history-cleanup-' . bin2hex(random_bytes(6));
        mkdir($this->logDirectory);
        Logger::setLogFile($this->logDirectory . '/main.log');
        $this->chat = new ChatAgent();
        $this->startAgent($this->chat);
        $this->drainSignals();
    }

    /** Releases local owners, registry fixtures and logger state even after an assertion fails. */
    protected function tearDown(): void
    {
        try {
            Hilos::$db->events->actions->deleteAll();
            foreach ($this->fileIds as $fileId) {
                if (isset(Hilos::$db->files[$fileId])) {
                    $storedName = Hilos::$db->files[$fileId]->storedName;
                    Hilos::$db->files[$fileId]->actions->delete();
                    Hilos::$fs->files[$storedName]->unlink();
                }
            }
            foreach ([$this->chat, ...$this->owners] as $agent) {
                TruthSourceRegistry::unregisterAgent($agent->getId());
                RtTruthSourceRegistry::unregisterAgent($agent->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
            }
            parent::tearDown();
        } finally {
            Logger::resetLogFile();
            foreach (array_diff(scandir($this->logDirectory), ['.', '..']) as $entry) {
                unlink($this->logDirectory . '/' . $entry);
            }
            rmdir($this->logDirectory);
        }
    }

    public function testEveryOwnerIncludingAFoldedPersonAnswersBeforeEventsAndFilesGo(): void
    {
        $first = $this->linkedRename();
        $second = $this->linkedRename();
        $folded = $this->linkedRename();
        $another = $this->linkedRename($first->userId);
        Hilos::$db->userMerges->actions->add($folded->userId, $first->userId);
        $file = $this->keepRegistryFile($first->userId, 'history.txt', 'kept until every answer');
        $this->fileIds[] = (int)$file->id;
        $message = Hilos::$db->events->actions->addMessage('attached', userId: $first->userId, fileIds: $this->fileIds);
        $eventIds = [$first->eventId, $second->eventId, $folded->eventId, $another->eventId, (int)$message->id];
        $this->drainSignals();

        $requests = $this->beginClear();

        self::assertSame([$first->userId, $second->userId, $folded->userId], array_column($requests, 'userId'));
        self::assertCount(1, array_unique(array_column($requests, 'clearId')));
        $this->assertHistoryIntact($eventIds);
        foreach ($requests as $index => $request) {
            $answer = $this->deliverUnlink($request);
            self::assertTrue($answer->unlinked);
            $this->assertHistoryIntact($eventIds);
            $this->deliverAnswer($answer);
            if ($index < 2) {
                $this->assertHistoryIntact($eventIds);
                self::assertSame([], $this->takeFileRemovals());
            }
        }

        $this->assertHistoryCleared($eventIds);
        self::assertSame([$this->fileIds], $this->takeFileRemovals());
        foreach ([$first, $second, $folded, $another] as $row) {
            $this->assertJournalUnlinked($row);
        }
        self::assertTrue($this->owner($folded->userId)->shouldStop());
        self::assertFalse($this->owner($first->userId)->shouldStop());
        self::assertFalse($this->owner($second->userId)->shouldStop());
    }

    public function testNoLinksClearsInTheCronHandlerWithoutAskingAnyone(): void
    {
        $row = $this->linkedRename();
        $eventIds = [$row->eventId];
        $this->deliverUnlink(new UserRenameFeedUnlinkSignalData($row->userId, 'already-unlinked'));
        $message = Hilos::$db->events->actions->addMessage('no journal links', userId: $row->userId);
        $eventIds[] = (int)$message->id;
        $this->drainSignals();

        $this->fireCron();

        $this->assertHistoryCleared($eventIds);
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(ChatSignalConstants::USER_RENAME_FEED_UNLINK, $signal->signalName->getName());
        }
        $this->assertJournalUnlinked($row);
    }

    public function testRepeatedForeignAndOldAnswersCannotCompleteTheAwaitedSet(): void
    {
        $first = $this->linkedRename();
        $second = $this->linkedRename();
        $eventIds = [$first->eventId, $second->eventId];
        $requests = $this->beginClear();
        $answer = $this->deliverUnlink($requests[0]);
        $this->deliverAnswer($answer);
        $this->deliverAnswer($answer);
        $this->deliverAnswer(new UserRenameFeedUnlinkedSignalData($second->userId, 'another-clear', true));
        $this->deliverAnswer(new UserRenameFeedUnlinkedSignalData($second->userId, 'another-clear', false));
        $this->deliverAnswer(new UserRenameFeedUnlinkedSignalData($second->userId + 1000, $requests[0]->clearId, false));
        $this->assertHistoryIntact($eventIds);
        self::assertSame([], $this->takeFileRemovals());
        self::assertFileDoesNotExist($this->chatErrorLog());

        $this->deliverAnswer($this->deliverUnlink($requests[1]));

        $this->assertHistoryCleared($eventIds);
        $this->takeFileRemovals();
        $this->deliverAnswer($answer);
        self::assertCount(1, EntityEvent::get([EntityEvent::type => ChatEventType::CHAT_CLEARED->value]));
        self::assertSame([], $this->takeFileRemovals());
    }

    public function testRefusalAbandonsTheAttemptAndTheNextCronStartsFromTheTable(): void
    {
        $first = $this->linkedRename();
        $second = $this->linkedRename();
        $eventIds = [$first->eventId, $second->eventId];
        $old = $this->beginClear();
        $answer = $this->deliverUnlink($old[0]);
        $this->deliverAnswer($answer);
        $this->deliverAnswer(new UserRenameFeedUnlinkedSignalData($second->userId, $old[0]->clearId, false));
        $this->deliverAnswer(new UserRenameFeedUnlinkedSignalData($second->userId, $old[0]->clearId, true));

        $this->assertHistoryIntact($eventIds);
        self::assertSame([], $this->takeFileRemovals());
        $error = (string)file_get_contents($this->chatErrorLog());
        self::assertStringContainsString("History clear {$old[0]->clearId} abandoned: user #{$second->userId}", $error);
        $new = $this->beginClear();
        self::assertSame([$second->userId], array_column($new, 'userId'));
        self::assertNotSame($old[0]->clearId, $new[0]->clearId);
        $this->deliverAnswer($answer);
        $this->assertHistoryIntact($eventIds);
        $this->deliverAnswer($this->deliverUnlink($new[0]));
        $this->assertHistoryCleared($eventIds);
    }

    public function testSilenceKeepsHistoryAndTheNextCronReplacesTheAttempt(): void
    {
        $first = $this->linkedRename();
        $second = $this->linkedRename();
        $eventIds = [$first->eventId, $second->eventId];
        $old = $this->beginClear();
        $this->deliverAnswer($this->deliverUnlink($old[0]));
        $this->assertHistoryIntact($eventIds);
        self::assertSame([], $this->takeFileRemovals());

        $new = $this->beginClear();

        self::assertSame([$second->userId], array_column($new, 'userId'));
        self::assertNotSame($old[0]->clearId, $new[0]->clearId);
        $error = (string)file_get_contents($this->chatErrorLog());
        self::assertStringContainsString("History clear {$old[0]->clearId} replaced: no unlink answer from users #{$second->userId}", $error);
        $oldAnswer = $this->deliverUnlink($old[1]);
        $this->deliverAnswer($oldAnswer);
        $this->assertHistoryIntact($eventIds);
        $this->deliverAnswer($this->deliverUnlink($new[0]));
        $this->assertHistoryCleared($eventIds);
    }

    public function testALinkWrittenAfterItsOwnersAnswerIsClearedByTheForeignKey(): void
    {
        $first = $this->linkedRename();
        $second = $this->linkedRename();
        $eventIds = [$first->eventId, $second->eventId];
        $requests = $this->beginClear();
        $this->deliverAnswer($this->deliverUnlink($requests[0]));
        $late = $this->linkedRename($first->userId);
        $eventIds[] = $late->eventId;
        $this->assertHistoryIntact($eventIds);

        $this->deliverAnswer($this->deliverUnlink($requests[1]));

        $this->assertHistoryCleared($eventIds);
        $this->assertJournalUnlinked($first);
        $this->assertJournalUnlinked($second);
        self::assertNotNull($late->eventId, 'The approved late-link path does not promise an in-memory FK notification');
        self::assertNull($this->storedRename($late)->event_id);
        self::assertSame([], Hilos::$db->userRenames->linkedUserIds(), 'Cleanup selects the database, not stale loaded links');
        $this->takeFileRemovals();
        $this->deliverUnlink(new UserRenameFeedUnlinkSignalData($first->userId, 'repair-memory'));
        $this->assertJournalUnlinked($late);
    }

    public function testPersonalUnlinkIsRepeatableAndDoesNotTouchAnotherPersonsRows(): void
    {
        $first = $this->linkedRename();
        $second = $this->linkedRename($first->userId);
        $other = $this->linkedRename();
        $otherEventId = $other->eventId;
        $request = new UserRenameFeedUnlinkSignalData($first->userId, 'personal-clear');

        self::assertTrue($this->deliverUnlink($request)->unlinked);
        self::assertTrue($this->deliverUnlink($request)->unlinked);

        $this->assertJournalUnlinked($first);
        $this->assertJournalUnlinked($second);
        self::assertSame($otherEventId, $other->eventId);
        self::assertSame($otherEventId, $this->storedRename($other)->event_id);
        self::assertFileDoesNotExist($this->personErrorLog($first->userId));
    }

    public function testAnErasedPersonsRequestStillAnswersSuccess(): void
    {
        $row = $this->linkedRename();
        $userId = $row->userId;
        Hilos::$db->userRenames->actions->deleteByUser($userId);
        Hilos::$db->users[$userId]->actions->delete();
        $this->drainSignals();

        $answer = $this->deliverUnlink(new UserRenameFeedUnlinkSignalData($userId, 'erased-clear'));

        self::assertTrue($answer->unlinked);
        self::assertSame($userId, $answer->userId);
        self::assertFalse($this->owner($userId)->shouldStop());
    }

    public function testAForeignAddresseeIsRefusedBeforeAnyUnlinkOrAnswer(): void
    {
        $first = $this->linkedRename();
        $other = $this->linkedRename();
        $owner = $this->owner($first->userId);
        $request = new UserRenameFeedUnlinkSignalData($other->userId, 'foreign-person');
        $this->drainSignals();
        try {
            $this->underAgent($owner, static fn () => $owner->onSignalAgent(
                new AgentSignalData($request), '', ChatSignalConstants::USER_RENAME_FEED_UNLINK,
            ));
            self::fail('A foreign addressee must be refused');
        } catch (AgentException) {
            self::assertNotNull($first->eventId);
            self::assertNotNull($other->eventId);
            self::assertNotNull($this->storedRename($first)->event_id);
            self::assertNotNull($this->storedRename($other)->event_id);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    public function testAWriteFailureAnswersFalseAndRollsBackAllLoadedRows(): void
    {
        $first = $this->linkedRename();
        $second = $this->linkedRename($first->userId);
        $eventIds = [$first->eventId, $second->eventId];
        $mirrors = new ReflectionProperty(SourceChangeBus::class, 'mirrors');
        $original = $mirrors->getValue();
        $failure = new HistoryCleanupUnlinkFailure($first->userId);
        SourceChangeBus::subscribe($failure);
        try {
            $answer = $this->deliverUnlink(new UserRenameFeedUnlinkSignalData($first->userId, 'refused-clear'));
        } finally {
            $mirrors->setValue(null, $original);
        }

        self::assertFalse($answer->unlinked);
        self::assertSame(2, $failure->updates, 'The refusal followed writes to both rows');
        self::assertSame($eventIds, [$first->eventId, $second->eventId], 'Existing View references observe rollback');
        self::assertSame($eventIds, [$this->storedRename($first)->event_id, $this->storedRename($second)->event_id]);
        $error = (string)file_get_contents($this->personErrorLog($first->userId));
        self::assertStringContainsString("user #{$first->userId}, clear refused-clear not written:", $error);
        self::assertStringNotContainsString('Private before', $error);
        self::assertStringNotContainsString('Private after', $error);
    }

    public function testChatCannotUnlinkTheJournalItReads(): void
    {
        $row = $this->linkedRename();
        $this->expectException(WriteNotAllowedException::class);
        $this->underAgent($this->chat, static fn () => Hilos::$db->userRenames->actions->unlinkEventsOf($row->userId));
    }

    public function testAPersonalOwnerCannotUnlinkAnotherPersonsSet(): void
    {
        $first = $this->linkedRename();
        $other = $this->linkedRename();
        $owner = $this->owner($first->userId);
        $this->expectException(WriteNotAllowedException::class);
        $this->underAgent($owner, static fn () => Hilos::$db->userRenames->actions->unlinkEventsOf($other->userId));
    }

    public function testUnlinkHandlersRejectTheWrongInnerDto(): void
    {
        $row = $this->linkedRename();
        $owner = $this->owner($row->userId);
        try {
            $this->underAgent($owner, static fn () => $owner->onSignalAgent(
                new AgentSignalData(new UserRenameFeedUnlinkedSignalData($row->userId, 'wrong-dto', true)),
                '', ChatSignalConstants::USER_RENAME_FEED_UNLINK,
            ));
            self::fail('The request handler must narrow its payload');
        } catch (InvalidAgentSignalPayloadException) {
            self::assertNotNull($row->eventId);
        }

        $this->expectException(InvalidAgentSignalPayloadException::class);
        $this->chat->onSignalAgent(
            new AgentSignalData(new UserRenameFeedUnlinkSignalData($row->userId, 'wrong-dto')),
            '', ChatSignalConstants::USER_RENAME_FEED_UNLINKED,
        );
    }

    /**
     * @param ?int $userId Existing person, or null to create another
     * @return UserRename Linked journal fixture
     */
    private function linkedRename(?int $userId = null): UserRename
    {
        $userId ??= (int)Hilos::$db->users->actions->createWithName('Private after')->id;
        $row = Hilos::$db->userRenames->actions->add($userId, $userId, 'Private before', 'Private after');
        $this->feedLineFor($row);

        return Hilos::$db->userRenames[(int)$row->id];
    }

    /** Delivers only cron; requests and their answers remain under the case's control. */
    private function fireCron(): void
    {
        $this->underAgent($this->chat, fn () => $this->chat->onSignalCron(
            new CronSignalDTO(ChatCronConstants::CLEANUP_HISTORY), '', ChatCronConstants::CLEANUP_HISTORY,
        ));
    }

    /** @return list<UserRenameFeedUnlinkSignalData> All queued owner requests, in address order */
    private function beginClear(): array
    {
        $this->drainSignals();
        $this->fireCron();
        $requests = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(HilosSignalConstants::HILOS_FILE_REMOVE, $signal->signalName->getName());
            if ($signal->signalName->getName() === ChatSignalConstants::USER_RENAME_FEED_UNLINK) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(UserRenameFeedUnlinkSignalData::class, $signal->data->data);
                $requests[] = UserRenameFeedUnlinkSignalData::fromJson($signal->data->data->toJson());
            }
        }

        return $requests;
    }

    /**
     * @param UserRenameFeedUnlinkSignalData $request One explicitly delivered owner request
     * @return UserRenameFeedUnlinkedSignalData The owner's single queued answer
     */
    private function deliverUnlink(UserRenameFeedUnlinkSignalData $request): UserRenameFeedUnlinkedSignalData
    {
        $owner = $this->owner($request->userId);
        $this->underAgent($owner, static fn () => $owner->onSignalAgent(
            new AgentSignalData($request), '', ChatSignalConstants::USER_RENAME_FEED_UNLINK,
        ));
        $answers = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(HilosSignalConstants::HILOS_FILE_REMOVE, $signal->signalName->getName());
            if ($signal->signalName->getName() === ChatSignalConstants::USER_RENAME_FEED_UNLINKED) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(UserRenameFeedUnlinkedSignalData::class, $signal->data->data);
                $answers[] = UserRenameFeedUnlinkedSignalData::fromJson($signal->data->data->toJson());
            }
        }
        self::assertCount(1, $answers);
        self::assertSame($request->userId, $answers[0]->userId);
        self::assertSame($request->clearId, $answers[0]->clearId);

        return $answers[0];
    }

    /** @param UserRenameFeedUnlinkedSignalData $answer Explicit continuation of this case's coordinator */
    private function deliverAnswer(UserRenameFeedUnlinkedSignalData $answer): void
    {
        $this->underAgent($this->chat, fn () => $this->chat->onSignalAgent(
            new AgentSignalData($answer), '', ChatSignalConstants::USER_RENAME_FEED_UNLINKED,
        ));
    }

    /** @return list<list<int>> File ids named by each queued removal frame */
    private function takeFileRemovals(): array
    {
        $removals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_FILE_REMOVE) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(FileRemoveSignalData::class, $signal->data->data);
                $removals[] = $signal->data->data->fileIds;
            }
        }

        return $removals;
    }

    /** @param list<int> $eventIds Events that must remain until every success */
    private function assertHistoryIntact(array $eventIds): void
    {
        foreach ($eventIds as $eventId) {
            self::assertArrayHasKey($eventId, Hilos::$db->events);
        }
        self::assertCount(0, EntityEvent::get([EntityEvent::type => ChatEventType::CHAT_CLEARED->value]));
    }

    /** @param list<int> $eventIds Events replaced by the single clear marker */
    private function assertHistoryCleared(array $eventIds): void
    {
        foreach ($eventIds as $eventId) {
            self::assertArrayNotHasKey($eventId, Hilos::$db->events);
        }
        self::assertCount(1, EntityEvent::getAll());
        self::assertSame(ChatEventType::CHAT_CLEARED->value, EntityEvent::getAll()->first()?->type);
    }

    /** @param UserRename $row Original journal reference, kept through the writes */
    private function assertJournalUnlinked(UserRename $row): void
    {
        self::assertNull($row->eventId);
        self::assertNull(Hilos::$db->userRenames[(int)$row->id]->eventId);
        $stored = $this->storedRename($row);
        self::assertNull($stored->event_id);
        self::assertSame('Private before', $stored->old_name);
        self::assertSame('Private after', $stored->new_name);
    }

    /**
     * @param UserRename $row Journal row to inspect independently of the Object cache
     * @return EntityUserRename Persisted row
     */
    private function storedRename(UserRename $row): EntityUserRename
    {
        $stored = EntityUserRename::get([EntityUserRename::id => $row->id])->first();
        self::assertNotNull($stored);

        return $stored;
    }

    /**
     * @param int $userId Person whose agent is started at most once in the case
     * @return UserAgent Local personal owner
     */
    private function owner(int $userId): UserAgent
    {
        return $this->owners[$userId] ??= $this->personAgent($userId);
    }

    /** @return string Coordinator's error stream path */
    private function chatErrorLog(): string
    {
        return AgentLogStream::pathFor($this->logDirectory, $this->chat->getId(), true);
    }

    /**
     * @param int $userId Personal owner whose errors are captured
     * @return string Personal error stream path
     */
    private function personErrorLog(int $userId): string
    {
        return AgentLogStream::pathFor($this->logDirectory, $this->owner($userId)->getId(), true);
    }
}

/** Fails the second write after both objects changed, once, so rollback must restore both. */
final class HistoryCleanupUnlinkFailure implements SourceMirrorSubscriberInterface
{
    public int $updates = 0;

    /** @param int $userId Person whose writes this fixture interrupts */
    public function __construct(private readonly int $userId)
    {
    }

    /**
     * @param SourceChange $change Source fact observed inside the local transaction
     * @param SourceChangeProvenance $provenance Source origin, unused
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind === SourceChange::KIND_DB && $change->sourceKey === HilosDbContext::userRenames
            && $change->mutationType === TableMutationType::Update && array_key_exists(EntityUserRename::event_id, $change->row)
            && $change->row[EntityUserRename::event_id] === null && $this->updates < 2) {
            $this->updates++;
            if ($this->updates === 2) {
                throw new HilosException("Scripted journal write failure for user #{$this->userId}");
            }
        }
    }
}
