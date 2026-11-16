<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Auth\ChatAccountDataOwnerDirectory;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventMessage as EntityEventMessage;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeProjectCensus;
use Hilos\Auth\AccountOperation\AccountMergeProjectStep;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Chat's prepared merge participant through real owner frames and the existing assignment signal.
 * Real commits and rollbacks prove result replay and message publication; reset the DB before running.
 */
final class ChatMergePartTest extends IntegrationTestCase
{
    private ChatAgent $chat;

    /** @var list<int> Registry fixtures removed after their attachment links */
    private array $fileIds = [];

    /** Starts the registered singleton with its production claims and no connected browsers. */
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

    /** Releases attachment files and the singleton's claims even after a failed assertion. */
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

    /** The actual installation expects the same singleton with and without loser content. */
    public function testTheChatDirectoryAndCensusExpectTheSingletonEvenWithoutRows(): void
    {
        self::assertSame(ChatAccountDataOwnerDirectory::class, Hilos::accountDataOwnerDirectoryClass());
        $owners = ChatAccountDataOwnerDirectory::declaredOwners();
        self::assertCount(1, $owners);
        self::assertSame('chat', $owners[0]->key);
        self::assertSame(AgentType::CHAT, $owners[0]->ownerType);
        self::assertFalse($owners[0]->perPerson);
        self::assertSame([
            ChatDbContext::events, ChatDbContext::eventMessages,
            ChatDbContext::eventUserRegistrations, ChatDbContext::eventAttachments,
        ], $owners[0]->collections);
        $loserId = (int)Hilos::$db->users->actions->createWithName('Census loser')->id;
        foreach ([false, true] as $withRows) {
            if ($withRows) {
                Hilos::$db->events->actions->addMessage('now there are rows', userId: $loserId);
            }
            $expected = AccountMergeProjectCensus::of($loserId);
            self::assertCount(1, $expected);
            self::assertSame('chat', $expected[0]->participantKey);
            self::assertSame($loserId, $expected[0]->userId);
            self::assertFalse($expected[0]->isWait());
            self::assertSame($this->chat->getId(), $expected[0]->owner->agentId());
            self::assertNull($expected[0]->owner->ownerIndex);
        }
    }

    /** Only loser authorship moves, including a message never loaded through ORM before the step. */
    public function testThePartMovesEveryLoserMessageAndPreservesAllOtherContent(): void
    {
        [$survivorId, $loserId] = $this->people();
        $thirdId = (int)Hilos::$db->users->actions->createWithName('Unrelated author')->id;
        $bot = Hilos::$db->bots->actions->create('Merge bystander bot', active: true);
        $file = $this->keepRegistryFile($loserId, 'merge.txt', 'attachment bytes stay');
        $this->fileIds[] = (int)$file->id;
        $first = (int)Hilos::$db->events->actions->addMessage('loser with file', userId: $loserId, fileIds: [(int)$file->id])->id;
        $second = (int)Hilos::$db->events->actions->addMessage('loser plain', userId: $loserId)->id;
        Hilos::$db->events->actions->addMessage('survivor stays', userId: $survivorId);
        Hilos::$db->events->actions->addMessage('third stays', userId: $thirdId);
        Hilos::$db->events->actions->addMessage('bot stays', botId: (int)$bot->id);
        Hilos::$db->events->actions->addUserRegistered($loserId);
        $this->feedLineFor(Hilos::$db->userRenames->actions->add($loserId, $loserId, 'Before merge', 'After rename'));
        $cold = $this->coldMessage($loserId);
        [, $assignment] = $this->ready($survivorId, $loserId);
        $messages = Database::sql('SELECT * FROM `event_message` ORDER BY `event_id`')->rows();
        $unchanged = $this->unchangedRows();
        $fileRow = Database::sql('SELECT * FROM `hilos_file` WHERE `id` = ?', [$file->id])->rows();
        $held = Hilos::$db->eventMessages[$first];
        $this->drainSignals();

        $result = $this->runPart($assignment);

        $this->assertPayload($result, 3);
        foreach ($messages as &$message) {
            if ((int)$message['author_user_id'] === $loserId) {
                $message['author_user_id'] = (string)$survivorId;
            }
        }
        unset($message);
        self::assertSame($messages, Database::sql('SELECT * FROM `event_message` ORDER BY `event_id`')->rows());
        self::assertSame($survivorId, $held->authorUserId);
        self::assertSame($survivorId, Hilos::$db->eventMessages[$cold]->authorUserId);
        self::assertSame($unchanged, $this->unchangedRows());
        self::assertSame($fileRow, Database::sql('SELECT * FROM `hilos_file` WHERE `id` = ?', [$file->id])->rows());
        self::assertSame('attachment bytes stay', Hilos::$fs->files[$file->storedName]->read());
        $updates = $this->takeMessageUpdates();
        self::assertSame([$first, $second, $cold], array_map(static fn (DbSyncUpdatedSignalData $data): int => (int)$data->idString, $updates));
        foreach ($updates as $update) {
            self::assertSame([EntityEventMessage::author_user_id => $survivorId], $update->row);
            self::assertSame([EntityEventMessage::author_user_id => $loserId], $update->previous);
        }
    }

    /** A registered owner with no loser messages still commits the exact zero result. */
    public function testAnEmptyAuthorRecordsZeroMessages(): void
    {
        [$survivorId, $loserId] = $this->people();
        [, $assignment] = $this->ready($survivorId, $loserId);
        $this->drainSignals();

        $this->assertPayload($this->runPart($assignment), 0);

        self::assertSame([], $this->takeMessageUpdates());
    }

    /** A failed result insert restores SQL, retained View/Object references and announcements. */
    public function testAResultWriteFailureRollsBackTheMoveAndRetainedReferences(): void
    {
        [$survivorId, $loserId] = $this->people();
        $messageId = (int)Hilos::$db->events->actions->addMessage('rollback author', userId: $loserId)->id;
        [, $assignment] = $this->ready($survivorId, $loserId);
        $view = Hilos::$db->eventMessages[$messageId];
        $object = $view->getObject();
        TruthSourceRegistry::unregister(HilosDbContext::accountOperationResults, $this->chat->getId());
        $this->drainSignals();

        try {
            $this->runPart($assignment);
            self::fail('Recording without the chat result Add claim must fail');
        } catch (CreateNotAllowedException) {
            self::assertSame($loserId, $view->authorUserId);
            self::assertSame($loserId, $object->authorUserId);
            self::assertSame($object, Hilos::$db->eventMessages[$messageId]->getObject());
            self::assertSame($loserId, (int)Database::sql(
                'SELECT `author_user_id` FROM `event_message` WHERE `event_id` = ?', [$messageId],
            )->rows()[0]['author_user_id']);
            $this->assertNoResult($assignment);
            self::assertSame([], $this->takeMessageUpdates());
        }
    }

    /** Replay after a stage change neither re-runs the move nor absorbs an artificially late row. */
    public function testReplayAfterCommitAndStageChangeReturnsTheSameResult(): void
    {
        [$survivorId, $loserId] = $this->people();
        Hilos::$db->events->actions->addMessage('move once', userId: $loserId);
        [$operation, $assignment] = $this->ready($survivorId, $loserId);
        $this->drainSignals();
        $first = $this->runPart($assignment);
        self::assertCount(1, $this->takeMessageUpdates());
        $late = (int)Hilos::$db->events->actions->addMessage('test-only late loser row', userId: $loserId)->id;
        self::assertTrue($this->underAgent($this->sessionsLibrary(), static fn (): bool => $operation->actions->advance(
            AccountMergeStage::Go->value, AccountMergeStage::Cancelling->value,
        )));
        $this->drainSignals();

        $repeat = $this->runPart($assignment);

        self::assertSame($first->id, $repeat->id);
        self::assertSame($first->payloadJson, $repeat->payloadJson);
        $this->assertPayload($repeat, 1);
        self::assertSame($loserId, Hilos::$db->eventMessages[$late]->authorUserId);
        self::assertSame([], $this->takeMessageUpdates());
    }

    /** An actual personal frame owns neither the chat assignment nor the room-wide update. */
    public function testAForeignFrameCannotRunTheAssignmentOrMoveAuthorsDirectly(): void
    {
        [$survivorId, $loserId] = $this->people();
        $messageId = (int)Hilos::$db->events->actions->addMessage('foreign frame refuses', userId: $loserId)->id;
        [, $assignment] = $this->ready($survivorId, $loserId);
        $foreign = $this->personAgent($survivorId);
        $this->drainSignals();
        try {
            $this->underAgent($foreign, fn (): AccountOperationResult => AccountMergeProjectStep::run($this->chat, $assignment->id));
            self::fail('A personal frame cannot answer a chat assignment');
        } catch (AccountOperationStepRefusedException $failure) {
            self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $failure->reason);
        }
        try {
            $this->underAgent($foreign, static fn (): int => Hilos::$db->eventMessages->actions->rePointAuthor($loserId, $survivorId));
            self::fail('A personal frame has no room-wide Update grant');
        } catch (WriteNotAllowedException) {
            self::assertSame($loserId, Hilos::$db->eventMessages[$messageId]->authorUserId);
            $this->assertNoResult($assignment);
            self::assertSame([], $this->takeMessageUpdates());
        }
    }

    /** A merge ask before the loser's fact refuses without falling through to erasure. */
    public function testAMergeAskBeforeTheFactWritesNothingAndDoesNotErase(): void
    {
        [$survivorId, $loserId] = $this->people();
        $messageId = (int)Hilos::$db->events->actions->addMessage('not given yet', userId: $loserId)->id;
        $operation = $this->openMerge($survivorId, $loserId);
        $assignment = $this->chatAssignment($operation, $loserId);
        $before = $this->unchangedRows();
        $this->drainSignals();
        try {
            $this->runPart($assignment);
            self::fail('A new merge part must wait for the loser fact');
        } catch (ValidationException $failure) {
            self::assertSame('An account merge part waits for the loser to give its rows away.', $failure->getMessage());
        }

        self::assertSame(AccountOperationAnswerKind::Refused, $this->deliverAsk($assignment)->answer);

        self::assertSame($loserId, Hilos::$db->eventMessages[$messageId]->authorUserId);
        self::assertSame($before, $this->unchangedRows());
        $this->assertNoResult($assignment);
    }

    /** The existing signal commits merge work, answers with correlation and repeats without work. */
    public function testTheExistingStepFrameMergesAndAnswersDoneWithoutRepeatingWork(): void
    {
        [$survivorId, $loserId] = $this->people();
        $messageId = (int)Hilos::$db->events->actions->addMessage('merge by frame', userId: $loserId)->id;
        [$operation, $assignment] = $this->ready($survivorId, $loserId);
        $this->drainSignals();

        $answer = $this->deliverAsk($assignment, expectedUpdates: 1);

        self::assertSame(new AccountOperationAnswerSignalData(
            $operation->id, $assignment->stage, $assignment->id, AccountOperationAnswerKind::Done,
        )->toArray(), $answer->toArray());
        self::assertSame($survivorId, Hilos::$db->eventMessages[$messageId]->authorUserId);
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        self::assertNotNull($result);
        $this->assertPayload($result, 1);
        self::assertSame($answer->toArray(), $this->deliverAsk($assignment)->toArray());
        self::assertSame($result->id, Hilos::$db->accountOperationResults->byAssignment($assignment->id)->id);
    }

    /** @return array{int, int} Fresh survivor and loser */
    private function people(): array
    {
        return [
            (int)Hilos::$db->users->actions->createWithName('Part survivor')->id,
            (int)Hilos::$db->users->actions->createWithName('Part loser')->id,
        ];
    }

    /**
     * Creates a cold message detail only at the fixture boundary, before its first ORM read.
     *
     * @param int $loserId Author whose unseen message must move too
     * @return int Message's event id
     */
    private function coldMessage(int $loserId): int
    {
        $event = ObjectEvent::create();
        $event->type = ChatEventType::MESSAGE_SENT->value;
        $event->timestamp = TimeHelper::nowMs();
        $event->sync();
        Database::sql(
            'INSERT INTO `event_message` (`event_id`, `author_user_id`, `message`) VALUES (?, ?, ?)',
            [$event->id, $loserId, 'cold loser message'],
        );

        return (int)$event->id;
    }

    /**
     * @param int $survivorId Recipient
     * @param int $loserId Source
     * @return AccountOperation Merge opened by the real coordinator owner
     */
    private function openMerge(int $survivorId, int $loserId): AccountOperation
    {
        return $this->underAgent($this->sessionsLibrary(), static fn (): AccountOperation => AccountMergeOpening::open(
            $survivorId, $loserId, new AccountMergeChoice(null, null, null, null),
        ));
    }

    /**
     * @param int $survivorId Recipient
     * @param int $loserId Source
     * @return array{AccountOperation, AccountOperationAssignment} Real prepared give fact and test-local chat assignment
     */
    private function ready(int $survivorId, int $loserId): array
    {
        $operation = $this->openMerge($survivorId, $loserId);
        foreach ([[$survivorId, AccountMergeDuty::PrepareSurvivor], [$loserId, AccountMergeDuty::PrepareLoser]] as [$userId, $duty]) {
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
            self::assertNotNull($assignment);
            $person = $this->personAgent($userId);
            $result = $this->underAgent($person, static fn (): AccountOperationResult => $person->prepareForMerge($assignment->id));
            self::assertTrue(AccountMergePreparationResult::of($result->payload)->isReserved());
        }
        self::assertTrue($this->underAgent($this->sessionsLibrary(), static fn (): bool => $operation->actions->advance(
            AccountMergeStage::Preparing->value, AccountMergeStage::Go->value,
        )));
        $give = $this->underAgent($this->sessionsLibrary(), static fn (): AccountOperationAssignment =>
            Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
                new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$loserId),
                $loserId, AccountOperationAssignmentPayload::fromJson('{}'),
            ));
        $loser = $this->personAgent($loserId);
        $this->underAgent($loser, static fn (): AccountOperationResult => $loser->giveForMerge($give->id));
        self::assertNotNull($operation->mergeFact());

        return [$operation, $this->chatAssignment($operation, $loserId)];
    }

    /**
     * @param AccountOperation $operation Operation whose current stage is used only by this fixture
     * @param int $loserId Source author
     * @return AccountOperationAssignment Test-local duty, without declaring a production coordinator key
     */
    private function chatAssignment(AccountOperation $operation, int $loserId): AccountOperationAssignment
    {
        return $this->underAgent($this->sessionsLibrary(), static fn (): AccountOperationAssignment =>
            Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, $operation->stage, 'test:chat-merge-part',
                new AccountOperationOwnerAddress(AgentType::CHAT), $loserId,
                AccountOperationAssignmentPayload::fromJson('{}'),
            ));
    }

    /**
     * @param AccountOperationAssignment $assignment Duty addressed to the chat singleton
     * @return AccountOperationResult Committed or replayed result
     */
    private function runPart(AccountOperationAssignment $assignment): AccountOperationResult
    {
        return $this->underAgent($this->chat, fn (): AccountOperationResult => AccountMergeProjectStep::run($this->chat, $assignment->id));
    }

    /**
     * @param AccountOperationAssignment $assignment Existing assignment delivered as a frame
     * @param int $expectedUpdates Number of committed message announcements
     * @return AccountOperationAnswerSignalData The sole correlated answer
     */
    private function deliverAsk(AccountOperationAssignment $assignment, int $expectedUpdates = 0): AccountOperationAnswerSignalData
    {
        $this->underAgent($this->chat, fn () => $this->chat->onSignalAgent(
            new AgentSignalData(new AccountOperationAskSignalData($assignment->operationId, $assignment->stage, $assignment->id, null)),
            '', ChatSignalConstants::CHAT_ACCOUNT_OPERATION_STEP,
        ));
        $answers = [];
        $updates = 0;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(AccountOperationAnswerSignalData::class, $signal->data->data);
                $answers[] = $signal->data->data;
            } elseif ($signal->data instanceof DbSyncUpdatedSignalData && $signal->data->collectionKey === ChatDbContext::eventMessages) {
                $updates++;
            }
        }
        self::assertCount(1, $answers);
        self::assertSame($expectedUpdates, $updates);

        return $answers[0];
    }

    /**
     * @param AccountOperationResult $result Durable participant result
     * @param int $messages Exact moved count
     */
    private function assertPayload(AccountOperationResult $result, int $messages): void
    {
        $stored = Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$result->assignmentId])->rows();
        self::assertCount(1, $stored);
        self::assertSame($result->id, (int)$stored[0]['id']);
        self::assertSame($this->chat->getId(), $stored[0]['owner_agent_id']);
        self::assertSame(['rows' => ['messages' => $messages]], json_decode($stored[0]['payload_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($stored[0]['payload_json'], $result->payloadJson);
    }

    /** @param AccountOperationAssignment $assignment Duty that has not completed */
    private function assertNoResult(AccountOperationAssignment $assignment): void
    {
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$assignment->id])->rows());
    }

    /** @return array<string, list<array<string, mixed>>> Feed links and rename journal that merge must leave untouched */
    private function unchangedRows(): array
    {
        return [
            'events' => Database::sql('SELECT * FROM `event` ORDER BY `id`')->rows(),
            'attachments' => Database::sql('SELECT * FROM `event_attachment` ORDER BY `event_id`, `file_id`')->rows(),
            'registrations' => Database::sql('SELECT * FROM `event_user_registration` ORDER BY `event_id`')->rows(),
            'renames' => Database::sql('SELECT * FROM `hilos_user_rename` ORDER BY `id`')->rows(),
        ];
    }

    /** @return list<DbSyncUpdatedSignalData> Published message updates; merge never queues file removal */
    private function takeMessageUpdates(): array
    {
        $updates = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(HilosSignalConstants::HILOS_FILE_REMOVE, $signal->signalName->getName());
            if ($signal->signalName->getName() === SignalConstants::DB_SYNC_UPDATED
                && $signal->data instanceof DbSyncUpdatedSignalData
                && $signal->data->collectionKey === ChatDbContext::eventMessages) {
                $updates[] = $signal->data;
            }
        }

        return $updates;
    }
}
