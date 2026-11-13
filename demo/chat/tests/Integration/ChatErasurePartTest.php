<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkSignalData;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkedSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\AccountOperation\AccountErasureCapture;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureProjectStep;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Chat's scaffold erasure participant through the common transactional step, without a live caller.
 * Real commits prove file removal after commit; reset the test database before running this class.
 */
final class ChatErasurePartTest extends IntegrationTestCase
{
    private ChatAgent $chat;

    /** @var list<int> Registry fixtures removed after their attachment rows */
    private array $fileIds = [];

    /** Starts the real chat owner over an empty feed and no connected browsers. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        $this->chat = new ChatAgent();
        $this->startAgent($this->chat);
        $this->drainSignals();
    }

    /** Releases real file fixtures and the chat owner's claims even after a case fails. */
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
        } finally {
            TruthSourceRegistry::unregisterAgent($this->chat->getId());
            RtTruthSourceRegistry::unregisterAgent($this->chat->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->chat->getId()));
            RtTruthSourceRegistry::unregisterAgent('test-agent');
            parent::tearDown();
        }
    }

    /** The person's feed goes, while the other person's rows and a rename authored by the person stay. */
    public function testThePartErasesOnlyItsPersonsFeedAndCommitsTheExactResult(): void
    {
        $personId = (int)Hilos::$db->users->actions->createWithName('Erasure part leaving')->id;
        $otherId = (int)Hilos::$db->users->actions->createWithName('Erasure part staying')->id;
        $firstMessage = (int)Hilos::$db->events->actions->addMessage('first goodbye', userId: $personId)->id;
        $otherMessage = (int)Hilos::$db->events->actions->addMessage('stay', userId: $otherId)->id;
        $registration = (int)Hilos::$db->events->actions->addUserRegistered($personId)->id;
        $otherRegistration = (int)Hilos::$db->events->actions->addUserRegistered($otherId)->id;
        $firstRename = $this->renameEvent($personId);
        $otherRename = $this->renameEvent($otherId, $personId);
        $fileId = $this->attachmentFile($personId);
        $secondMessage = (int)Hilos::$db->events->actions->addMessage('last goodbye', userId: $personId, fileIds: [$fileId])->id;
        $secondRename = $this->renameEvent($personId);
        $operation = $this->openErasure($personId);
        $this->capture($operation, $personId);
        $assignment = $this->chatAssignment($operation, $personId);
        $this->drainSignals();

        $result = $this->runPart($assignment);

        $this->assertPayload($result, ['rows' => ['messages' => 2, 'attachments' => 1, 'events' => 5], 'files' => [$fileId]]);
        $this->assertEvents([$firstMessage, $secondMessage, $registration, $firstRename, $secondRename], false);
        $this->assertEvents([$otherMessage, $otherRegistration, $otherRename], true);
        self::assertSame([], Database::sql('SELECT * FROM `event_message` WHERE `author_user_id` = ?', [$personId])->rows());
        self::assertSame([], Database::sql('SELECT * FROM `event_attachment` WHERE `event_id` = ?', [$secondMessage])->rows());
        self::assertSame([], Database::sql('SELECT * FROM `event_user_registration` WHERE `target_user_id` = ?', [$personId])->rows());
        self::assertCount(1, Database::sql('SELECT * FROM `event_message` WHERE `event_id` = ?', [$otherMessage])->rows());
        self::assertCount(1, Database::sql('SELECT * FROM `event_user_registration` WHERE `event_id` = ?', [$otherRegistration])->rows());
        $otherJournal = Database::sql('SELECT * FROM `hilos_user_rename` WHERE `event_id` = ?', [$otherRename])->rows();
        self::assertCount(1, $otherJournal);
        self::assertSame($otherId, (int)$otherJournal[0]['user_id']);
        self::assertSame($personId, (int)$otherJournal[0]['renamed_by_user_id'], 'Personal erasure has not deleted the account yet');
        self::assertSame([[$fileId]], $this->takeFileRemovals(), 'Exactly one file removal follows the committed result');
    }

    /** An uncaptured part refuses without deleting a row or recording an outcome. */
    public function testMissingCaptureRefusesBeforeAnyFeedWrite(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Uncaptured chat part')->id;
        $feed = $this->seedFeed($userId);
        $operation = $this->openErasure($userId);
        $assignment = $this->chatAssignment($operation, $userId);
        $this->drainSignals();

        try {
            $this->runPart($assignment);
            self::fail('The part must wait for the personal capture');
        } catch (ValidationException $failure) {
            self::assertSame('A chat erasure part waits for the person\'s capture.', $failure->getMessage());
        }

        $this->assertFeedIntact($userId, $feed);
        self::assertCount(2, Database::sql(
            'SELECT * FROM `hilos_user_rename` WHERE `user_id` = ? AND `event_id` IS NOT NULL', [$userId],
        )->rows());
        $this->assertNoResult($assignment);
        self::assertSame([], $this->takeFileRemovals());
    }

    /** A registered chat participant is expected even for an account with no feed rows. */
    public function testAnEmptyFeedRecordsAllThreeZeroCounts(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Empty chat part')->id;
        $operation = $this->openErasure($userId);
        $this->capture($operation, $userId);
        $this->drainSignals();

        $result = $this->runPart($this->chatAssignment($operation, $userId));

        $this->assertPayload($result, ['rows' => ['messages' => 0, 'attachments' => 0, 'events' => 0], 'files' => []]);
        self::assertSame([], $this->takeFileRemovals());
    }

    /** Retrying the same assignment reads the immutable result and does no work again. */
    public function testARepeatReturnsTheSavedResultWithoutAnotherFileRemoval(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Repeated chat part')->id;
        $feed = $this->seedFeed($userId);
        $operation = $this->openErasure($userId);
        $this->capture($operation, $userId);
        $assignment = $this->chatAssignment($operation, $userId);
        $this->drainSignals();
        $first = $this->runPart($assignment);
        self::assertSame([[$feed['file']]], $this->takeFileRemovals());

        $second = $this->runPart($assignment);

        self::assertSame($first->id, $second->id);
        self::assertSame($first->payloadJson, $second->payloadJson);
        $this->assertPayload($second, ['rows' => ['messages' => 2, 'attachments' => 1, 'events' => 5], 'files' => [$feed['file']]]);
        self::assertCount(1, Database::sql(
            'SELECT * FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$assignment->id],
        )->rows());
        self::assertSame([], $this->takeFileRemovals());
    }

    /** A result-write refusal after erasing rows rolls back the whole common step. */
    public function testFailureToRecordTheResultRollsBackEveryDeletionAndFileRemoval(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Rolled back chat part')->id;
        $feed = $this->seedFeed($userId);
        $operation = $this->openErasure($userId);
        $this->capture($operation, $userId);
        $assignment = $this->chatAssignment($operation, $userId);
        TruthSourceRegistry::unregister(HilosDbContext::accountOperationResults, $this->chat->getId());
        $this->drainSignals();

        try {
            $this->runPart($assignment);
            self::fail('Recording without the participant result claim must fail');
        } catch (CreateNotAllowedException) {
            $this->assertFeedIntact($userId, $feed);
            $this->assertNoResult($assignment);
            self::assertSame([], $this->takeFileRemovals());
        }
    }

    /** Each account of the circle has a separate capture and part, including a folded account. */
    public function testAFoldedAccountUsesItsOwnCaptureAndLeavesTheSurvivorsFeed(): void
    {
        $survivorId = (int)Hilos::$db->users->actions->createWithName('Circle survivor')->id;
        $foldedId = (int)Hilos::$db->users->actions->createWithName('Circle folded')->id;
        Hilos::$db->userMerges->actions->add($foldedId, $survivorId);
        Hilos::$db->users[$foldedId]->actions->setBlock(true);
        $foldedRegistration = (int)Hilos::$db->events->actions->addUserRegistered($foldedId)->id;
        $foldedRename = $this->renameEvent($foldedId);
        $survivorRegistration = (int)Hilos::$db->events->actions->addUserRegistered($survivorId)->id;
        $survivorRename = $this->renameEvent($survivorId);
        $survivorMessage = (int)Hilos::$db->events->actions->addMessage('survives this part', userId: $survivorId)->id;
        $operation = $this->openErasure($survivorId);
        self::assertContains($foldedId, $operation->circle->toArray());
        $this->capture($operation, $foldedId);
        $this->capture($operation, $survivorId);
        $this->drainSignals();

        $result = $this->runPart($this->chatAssignment($operation, $foldedId));

        $this->assertPayload($result, ['rows' => ['messages' => 0, 'attachments' => 0, 'events' => 2], 'files' => []]);
        $this->assertEvents([$foldedRegistration, $foldedRename], false);
        $this->assertEvents([$survivorRegistration, $survivorRename, $survivorMessage], true);
        self::assertSame([], Database::sql('SELECT * FROM `event_user_registration` WHERE `target_user_id` = ?', [$foldedId])->rows());
        self::assertCount(1, Database::sql('SELECT * FROM `event_user_registration` WHERE `target_user_id` = ?', [$survivorId])->rows());
        self::assertCount(1, Database::sql('SELECT * FROM `event_message` WHERE `author_user_id` = ?', [$survivorId])->rows());
        self::assertSame([], $this->takeFileRemovals());
    }

    /** Captured rename ids may already be gone when history cleanup wins the order. */
    public function testHistoryClearedBeforeThePartLeavesAZeroResult(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('History cleared first')->id;
        $feed = $this->seedFeed($userId);
        $operation = $this->openErasure($userId);
        $this->capture($operation, $userId);
        $this->drainSignals();
        $this->fireCron();
        self::assertSame([[$feed['file']]], $this->takeFileRemovals());
        $this->assertEvents($feed['events'], false);

        $result = $this->runPart($this->chatAssignment($operation, $userId));

        $this->assertPayload($result, ['rows' => ['messages' => 0, 'attachments' => 0, 'events' => 0], 'files' => []]);
        self::assertSame([], $this->takeFileRemovals());
    }

    /** Pending journal replies continue the clear over rows remaining after the person's part. */
    public function testThePartDuringHistoryClearRemovesItsFileOnlyOnce(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Erased during clear')->id;
        $otherId = (int)Hilos::$db->users->actions->createWithName('Cleared after erasure')->id;
        $feed = $this->seedFeed($userId);
        $otherRename = $this->renameEvent($otherId);
        $otherFileId = $this->attachmentFile($otherId);
        $otherMessage = (int)Hilos::$db->events->actions->addMessage('clear later', userId: $otherId, fileIds: [$otherFileId])->id;
        $requests = $this->beginClear();
        self::assertSame([$userId, $otherId], array_map(static fn (UserRenameFeedUnlinkSignalData $request): int => $request->userId, $requests));
        $operation = $this->openErasure($userId);
        $this->capture($operation, $userId);
        $this->drainSignals();

        $result = $this->runPart($this->chatAssignment($operation, $userId));

        $this->assertPayload($result, ['rows' => ['messages' => 2, 'attachments' => 1, 'events' => 5], 'files' => [$feed['file']]]);
        self::assertSame([[$feed['file']]], $this->takeFileRemovals());
        $this->assertEvents($feed['events'], false);
        $this->assertEvents([$otherRename, $otherMessage], true);
        foreach ($requests as $offset => $request) {
            $answer = $this->deliverUnlink($request);
            $this->underAgent($this->chat, fn () => $this->chat->onSignalAgent(
                new AgentSignalData($answer), '', ChatSignalConstants::USER_RENAME_FEED_UNLINKED,
            ));
            if ($offset === 0) {
                self::assertSame([], $this->takeFileRemovals());
                $this->assertEvents([$otherRename, $otherMessage], true);
            }
        }
        $this->assertEvents([$otherRename, $otherMessage], false);
        self::assertCount(1, Database::sql('SELECT * FROM `event` WHERE `type` = ?', [ChatEventType::CHAT_CLEARED->value])->rows());
        self::assertSame([[$otherFileId]], $this->takeFileRemovals(), 'Cleanup names only the remaining attachment');
    }

    /**
     * @param int $userId Person to seed
     * @return array{events: list<int>, file: int, attachmentEvent: int} Five feed events and their attachment
     */
    private function seedFeed(int $userId): array
    {
        $fileId = $this->attachmentFile($userId);
        $first = (int)Hilos::$db->events->actions->addMessage('attached goodbye', userId: $userId, fileIds: [$fileId])->id;
        $second = (int)Hilos::$db->events->actions->addMessage('plain goodbye', userId: $userId)->id;
        $registration = (int)Hilos::$db->events->actions->addUserRegistered($userId)->id;

        return [
            'events' => [$first, $second, $registration, $this->renameEvent($userId), $this->renameEvent($userId)],
            'file' => $fileId,
            'attachmentEvent' => $first,
        ];
    }

    /**
     * @param int $userId Person renamed
     * @param ?int $authorId Rename author, the person when omitted
     * @return int Linked rename feed event
     */
    private function renameEvent(int $userId, ?int $authorId = null): int
    {
        return (int)$this->feedLineFor(Hilos::$db->userRenames->actions->add($userId, $authorId ?? $userId, 'Before', 'After'))->id;
    }

    /**
     * @param int $userId File's owner
     * @return int Bound registry file, cleaned up after the case
     */
    private function attachmentFile(int $userId): int
    {
        $fileId = (int)$this->keepRegistryFile($userId, 'erasure.txt', 'chat erasure attachment')->id;
        $this->fileIds[] = $fileId;

        return $fileId;
    }

    /**
     * @param int $userId Root of the erasure circle
     * @return AccountOperation Open erasure with census assignments at its current stage
     */
    private function openErasure(int $userId): AccountOperation
    {
        $library = $this->usersLibrary();
        $effectiveAt = TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        $this->underAgent($library, static fn () => Hilos::$db->accountDeletions->actions->request($userId, $effectiveAt));
        $request = Hilos::$db->accountDeletions->liveOf($userId);
        self::assertNotNull($request);
        self::assertTrue($this->underAgent($this->personAgent($userId), static fn (): bool => $request->actions->start()));
        $catalog = AccountErasureParticipantCatalog::installation();
        $operation = $this->underAgent(
            $this->sessionsLibrary(), static fn (): ?AccountOperation => AccountErasureOpening::open($request, $catalog)->operation,
        );
        self::assertNotNull($operation);

        return $operation;
    }

    /**
     * @param AccountOperation $operation Erasure whose personal capture is needed
     * @param int $userId Account of its circle
     */
    private function capture(AccountOperation $operation, int $userId): void
    {
        $catalog = AccountErasureParticipantCatalog::installation();
        $this->underAgent($this->sessionsLibrary(), static fn (): array => AccountErasureCapture::recordAfterStart($operation, $catalog));
        $assignment = $this->assignmentByKey($operation, AccountErasureCapture::personalKey($userId));
        $agent = $this->personAgent($userId);
        $this->underAgent($agent, static fn (): AccountOperationResult => $agent->captureForErasure($assignment->id));
    }

    /**
     * @param AccountOperation $operation Erasure that expects the chat owner
     * @param int $userId Account whose part is assigned
     * @return AccountOperationAssignment Census assignment used before the coordinator's live frame exists
     */
    private function chatAssignment(AccountOperation $operation, int $userId): AccountOperationAssignment
    {
        return $this->assignmentByKey($operation, 'expect:chat:chat:' . $userId);
    }

    /**
     * @param AccountOperation $operation Operation to search
     * @param string $key Exact assignment key
     * @return AccountOperationAssignment The expected assignment
     */
    private function assignmentByKey(AccountOperation $operation, string $key): AccountOperationAssignment
    {
        $assignments = [];
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            if ($assignment->assignmentKey === $key) {
                $assignments[] = $assignment;
            }
        }
        self::assertCount(1, $assignments, $key);

        return $assignments[0];
    }

    /**
     * @param AccountOperationAssignment $assignment Chat's assignment
     * @return AccountOperationResult Committed or previously saved result
     */
    private function runPart(AccountOperationAssignment $assignment): AccountOperationResult
    {
        return $this->underAgent($this->chat, fn (): AccountOperationResult => AccountErasureProjectStep::run($this->chat, $assignment->id));
    }

    /**
     * @param AccountOperationResult $result Result returned by the step
     * @param array{rows: array{messages: int, attachments: int, events: int}, files: list<int>} $expected Exact stored payload
     */
    private function assertPayload(AccountOperationResult $result, array $expected): void
    {
        $rows = Database::sql('SELECT `payload_json`, `owner_agent_id` FROM `hilos_account_operation_result` WHERE `id` = ?', [$result->id])->rows();
        self::assertCount(1, $rows);
        self::assertSame('chat', $rows[0]['owner_agent_id']);
        self::assertSame($expected, json_decode($rows[0]['payload_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($rows[0]['payload_json'], $result->payloadJson);
    }

    /**
     * @param list<int> $eventIds Events to check through independent SQL
     * @param bool $present Whether each event must exist
     */
    private function assertEvents(array $eventIds, bool $present): void
    {
        foreach ($eventIds as $eventId) {
            self::assertCount($present ? 1 : 0, Database::sql('SELECT `id` FROM `event` WHERE `id` = ?', [$eventId])->rows());
        }
    }

    /**
     * @param int $userId Person whose feed remains
     * @param array{events: list<int>, file: int, attachmentEvent: int} $feed Seeded feed
     */
    private function assertFeedIntact(int $userId, array $feed): void
    {
        $this->assertEvents($feed['events'], true);
        self::assertCount(2, Database::sql('SELECT * FROM `event_message` WHERE `author_user_id` = ?', [$userId])->rows());
        self::assertCount(1, Database::sql('SELECT * FROM `event_attachment` WHERE `event_id` = ?', [$feed['attachmentEvent']])->rows());
        self::assertCount(1, Database::sql('SELECT * FROM `event_user_registration` WHERE `target_user_id` = ?', [$userId])->rows());
    }

    /** @param AccountOperationAssignment $assignment Assignment that must have no result */
    private function assertNoResult(AccountOperationAssignment $assignment): void
    {
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$assignment->id])->rows());
    }

    /** Delivers the history timer explicitly, without waiting for a real clock. */
    private function fireCron(): void
    {
        $this->underAgent($this->chat, fn () => $this->chat->onSignalCron(
            new CronSignalDTO(ChatCronConstants::CLEANUP_HISTORY), '', ChatCronConstants::CLEANUP_HISTORY,
        ));
    }

    /** @return list<UserRenameFeedUnlinkSignalData> Requests held while the erasure part runs */
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
                $requests[] = $signal->data->data;
            }
        }

        return $requests;
    }

    /**
     * @param UserRenameFeedUnlinkSignalData $request Pending history unlink request
     * @return UserRenameFeedUnlinkedSignalData The real owner's reply
     */
    private function deliverUnlink(UserRenameFeedUnlinkSignalData $request): UserRenameFeedUnlinkedSignalData
    {
        $owner = $this->personAgent($request->userId);
        $this->underAgent($owner, static fn () => $owner->onSignalAgent(
            new AgentSignalData($request), '', ChatSignalConstants::USER_RENAME_FEED_UNLINK,
        ));
        $answers = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(HilosSignalConstants::HILOS_FILE_REMOVE, $signal->signalName->getName());
            if ($signal->signalName->getName() === ChatSignalConstants::USER_RENAME_FEED_UNLINKED) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(UserRenameFeedUnlinkedSignalData::class, $signal->data->data);
                $answers[] = $signal->data->data;
            }
        }
        self::assertCount(1, $answers);
        self::assertSame($request->userId, $answers[0]->userId);
        self::assertSame($request->clearId, $answers[0]->clearId);
        self::assertTrue($answers[0]->unlinked);

        return $answers[0];
    }

    /** @return list<list<int>> Registry ids in each queued file removal */
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
}
