<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergePart;
use Hilos\Auth\AccountOperation\AccountMergePersonalJoin;
use Hilos\Auth\AccountOperation\AccountMergeProjectResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountMergePersonalHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The project part of a merge over the real exchange (HIL-2203): the loser's agent gives the rows of
 * its personal set in one serialized frame, the survivor's agent joins them into its own set, the
 * loser's agent then deletes its own, and the session holder ends the merge only on both results.
 */
final class AccountMergePersonalPartIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int SURVIVOR = 7;
    private const int LOSER = 9;
    private const int OTHER = 11;
    private const array EXTRA_TABLES = ['hilos_passkey_credential', 'hilos_account_merge_copy'];
    private const string REPLY = 'merge-personal-part-answer';

    private MergePersonalPartHolder $holder;
    private string $previousApp;
    private ?SignalRouter $previousRouter;
    /** Calls of the example's join, failed ones included. */
    private int $joins = 0;
    /** Calls of the example's delete, failed ones included. */
    private int $deletes = 0;
    /** Whether the example's join throws after its writes. */
    private bool $failJoin = false;
    /** Whether the example's delete throws after its writes. */
    private bool $failDelete = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->stubs(true);
        $this->stubs(false);
        Database::sqlRun('DROP TABLE IF EXISTS `' . MergePersonalPartRow::_table . '`');
        Database::sqlRun('CREATE TABLE `' . MergePersonalPartRow::_table . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `user_id` INT UNSIGNED NOT NULL, `label` VARCHAR(64) NOT NULL, PRIMARY KEY (`id`))');
        Schema::reset();
        Schema::initialize();
        $this->freshContext();
        MergePersonalPartHilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->holder = new MergePersonalPartHolder();
        OwnershipDeclaration::claimAll($this->holder);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser'), (11, 'Other')");
        Database::sqlRun('INSERT INTO `' . MergePersonalPartRow::_table . '` (`user_id`, `label`)'
            . " VALUES (7, 'survivor note'), (9, 'loser note'), (9, 'loser wish'), (11, 'other note')");
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            Database::sqlRun('DROP TABLE IF EXISTS `' . MergePersonalPartRow::_table . '`');
            $this->stubs(true);
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent($this->holder->getId());
            RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
            Hilos::$sr = $this->previousRouter;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    /**
     * @param string $userId Person addressed by the registered indexed route
     * @return AbstractUserAgent The agent the installation registers: the example that joins, or the framework's own
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        if (!is_a(Hilos::appClass()::AGENTS[HilosAgentType::HILOS_USER][AgentRegistryKey::WORKER], AccountMergePersonalJoin::class, true)) {
            return new IntegrationUserAgent($userId);
        }

        return new MergePersonalPartUser($userId, $this->giveRows(...), $this->joinRows(...), $this->deleteRows(...));
    }

    /** @return iterable<string, array{class-string<Hilos>, array<string, string>, list<string>, list<string>, bool}> Installation and its outcome */
    public static function installations(): iterable
    {
        $framework = [
            'give' => 'hilos_user#9',
            'receive' => 'hilos_user#7',
            'sessions' => 'hilos_sessions_library#',
            'survivor_sessions' => 'hilos_sessions_library#',
            'cleanup' => 'hilos_user#9',
        ];
        $stayed = [['survivor note'], ['loser note', 'loser wish'], false];
        yield 'person agent joins' => [
            MergePersonalPartHilos::class,
            [...$framework, 'personal_give' => 'hilos_user#9', 'personal_receive' => 'hilos_user#7'],
            ['survivor note', 'loser note', 'loser wish'], [], true,
        ];
        yield 'person agent without the contract' => [MergePersonalPartWithoutJoinHilos::class, $framework, ...$stayed];
        yield 'nothing personal declared' => [MergePersonalPartUndeclaredHilos::class, $framework, ...$stayed];
    }

    /**
     * (a) The two personal duties exist only where the part is active; elsewhere the merge ends as before it and the rows stay.
     *
     * @param class-string<Hilos> $hilos Installation of the case
     * @param array<string, string> $duties Go duties in recording order, with their owners
     * @param list<string> $survivor Labels the survivor holds at the end
     * @param list<string> $loser Labels the loser holds at the end
     * @param bool $active Whether the part runs: its frame travels, and the example joins and deletes once each
     */
    #[DataProvider('installations')]
    public function testGoRecordsThePersonalDutiesOnlyWhereThePartIsActive(
        string $hilos, array $duties, array $survivor, array $loser, bool $active,
    ): void {
        $hilos::initBrowser();
        [$operation, $give] = $this->readyGo();
        self::assertSame($duties, $this->goDuties($operation));
        $this->deliver($give);
        $delivered = $this->finishExchange($operation);
        self::assertSame($active, $this->personalFrames($delivered) !== []);
        self::assertSame($survivor, $this->labelsOf(self::SURVIVOR));
        self::assertSame($loser, $this->labelsOf(self::LOSER));
        self::assertSame(['other note'], $this->labelsOf(self::OTHER));
        self::assertSame([(int)$active, (int)$active], [$this->joins, $this->deletes]);
    }

    /** (b) Give sends the frame and waits, the survivor joins on it, the next poll deletes, and the merge ends. */
    public function testLoserGivesSurvivorJoinsAndTheNextPollDeletesToTheEnd(): void
    {
        [$operation, $give] = $this->readyGo();
        $personalGive = $this->duty($operation, AccountMergeDuty::PersonalGive);
        $personalReceive = $this->duty($operation, AccountMergeDuty::PersonalReceive);
        $this->deliver($give);
        [$handover, $giveAnswer] = $this->frames();
        self::assertInstanceOf(AccountMergeHandoverSignalData::class, $handover->data->data);
        $this->deliver($giveAnswer);
        $asks = $this->frames();
        [$ask] = $this->asksOf($asks, AccountMergeDuty::PersonalGive);

        $this->deliver($ask);
        [$frame, $waits] = $this->frames();
        self::assertSame(HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_PERSONAL_HANDOVER, $frame->signalName->getName());
        self::assertSame('agent/hilos_user#9', SignalSource::describe($frame->signalSource));
        self::assertSame([
            'operationId' => $operation->id,
            'stage' => 'go',
            'assignmentId' => $personalReceive->id,
            'ownerIndex' => '7',
            'rows' => ['items' => [['label' => 'loser note'], ['label' => 'loser wish']]],
        ], $frame->data->data->toArray());
        self::assertSame([$personalGive->id, AccountOperationAnswerKind::Waits],
            [$waits->data->data->assignmentId, $waits->data->data->answer]);
        self::assertSame([[], []], [$this->resultsOf($personalGive), $this->resultsOf($personalReceive)]);
        self::assertSame(['loser note', 'loser wish'], $this->labelsOf(self::LOSER));
        $this->deliver($waits);
        self::assertSame([], $this->frames());

        // Every poll before the survivor's result sends the same rows again and writes nothing.
        $this->deliver($ask);
        [$again, $waitsAgain] = $this->frames();
        self::assertSame($frame->data->data->toArray(), $again->data->data->toArray());
        self::assertSame(AccountOperationAnswerKind::Waits, $waitsAgain->data->data->answer);
        $this->deliver($waitsAgain);
        self::assertSame([[], []], [$this->resultsOf($personalGive), $this->resultsOf($personalReceive)]);

        $this->freshContext();
        $this->deliver($frame);
        [$joined] = $this->frames();
        self::assertSame([$personalReceive->id, AccountOperationAnswerKind::Done],
            [$joined->data->data->assignmentId, $joined->data->data->answer]);
        self::assertSame(['{"rows":{"items":2}}'], $this->resultsOf($personalReceive));
        self::assertSame('hilos_user:7', Hilos::$db->accountOperationResults->byAssignment($personalReceive->id)->ownerAgentId);
        self::assertSame([], $this->resultsOf($personalGive));
        self::assertSame(['survivor note', 'loser note', 'loser wish'], $this->labelsOf(self::SURVIVOR));
        self::assertSame(['loser note', 'loser wish'], $this->labelsOf(self::LOSER));

        $this->deliver($joined);
        $next = $this->frames();
        [$deletion] = $this->asksOf($next, AccountMergeDuty::PersonalGive);
        $this->deliver($deletion);
        [$deleted] = $this->frames();
        self::assertSame([$personalGive->id, AccountOperationAnswerKind::Done],
            [$deleted->data->data->assignmentId, $deleted->data->data->answer]);
        self::assertSame(['{"rows":{"items":2}}'], $this->resultsOf($personalGive));
        self::assertSame('hilos_user:9', Hilos::$db->accountOperationResults->byAssignment($personalGive->id)->ownerAgentId);
        self::assertSame([], $this->labelsOf(self::LOSER));
        self::assertSame(['survivor note', 'loser note', 'loser wish'], $this->labelsOf(self::SURVIVOR));
        self::assertNull($this->row($operation)['finished_at']);

        foreach ([...$this->exceptPersonalGive($asks), ...$this->exceptPersonalGive($next), $deleted, $handover] as $remaining) {
            $this->deliver($remaining);
        }
        $this->finishExchange($operation);
        self::assertSame([1, 1], [$this->joins, $this->deletes]);
        self::assertSame(['other note'], $this->labelsOf(self::OTHER));
    }

    /** (c) A repeated frame and a repeated poll answer the saved results: the join and the delete ran once each. */
    public function testRepeatedFrameAndPollAnswerSavedResultsWithoutJoiningOrDeletingAgain(): void
    {
        [$operation, $give] = $this->readyGo();
        $personalGive = $this->duty($operation, AccountMergeDuty::PersonalGive);
        $personalReceive = $this->duty($operation, AccountMergeDuty::PersonalReceive);
        $this->deliver($give);
        $delivered = $this->finishExchange($operation);
        [$frame] = $this->personalFrames($delivered);
        [$ask] = $this->asksOf($delivered, AccountMergeDuty::PersonalGive);
        $saved = [$this->resultsOf($personalGive), $this->resultsOf($personalReceive)];
        self::assertSame([['{"rows":{"items":2}}'], ['{"rows":{"items":2}}']], $saved);
        self::assertSame([1, 1], [$this->joins, $this->deletes]);

        $this->freshContext();
        $this->deliver($frame);
        [$replay] = $this->frames();
        self::assertSame([$personalReceive->id, AccountOperationAnswerKind::Done],
            [$replay->data->data->assignmentId, $replay->data->data->answer]);
        $this->deliver($replay);
        self::assertSame([], $this->frames());
        $this->deliver($ask);
        [$poll] = $this->frames();
        self::assertSame([$personalGive->id, AccountOperationAnswerKind::Done], [$poll->data->data->assignmentId, $poll->data->data->answer]);
        $this->deliver($poll);
        self::assertSame([], $this->frames());

        self::assertSame($saved, [$this->resultsOf($personalGive), $this->resultsOf($personalReceive)]);
        self::assertSame([1, 1], [$this->joins, $this->deletes]);
        self::assertSame(['survivor note', 'loser note', 'loser wish'], $this->labelsOf(self::SURVIVOR));
        self::assertSame([], $this->labelsOf(self::LOSER));
        self::assertSame('completed', $this->row($operation)['outcome']);
    }

    /** (d) A loser with nothing to give still records both results, as exactly an empty rows object, and the merge ends. */
    public function testEmptyLoserRecordsTwoEmptyResultsAndTheMergeEnds(): void
    {
        Database::sqlRun('DELETE FROM `' . MergePersonalPartRow::_table . '` WHERE `user_id` = ?', [self::LOSER]);
        [$operation, $give] = $this->readyGo();
        $personalGive = $this->duty($operation, AccountMergeDuty::PersonalGive);
        $personalReceive = $this->duty($operation, AccountMergeDuty::PersonalReceive);
        $this->deliver($give);
        $delivered = $this->finishExchange($operation);
        $frames = $this->personalFrames($delivered);
        self::assertNotSame([], $frames);
        self::assertSame([], $frames[0]->data->data->rows);
        foreach ([$personalGive, $personalReceive] as $duty) {
            self::assertSame(['{"rows":{}}'], $this->resultsOf($duty));
            self::assertSame([], AccountMergeProjectResult::rowsMovedOf(Hilos::$db->accountOperationResults->byAssignment($duty->id)->payload));
        }
        self::assertSame(['survivor note'], $this->labelsOf(self::SURVIVOR));
        self::assertSame([], $this->labelsOf(self::LOSER));
    }

    /** (e) A join that throws rolls its writes back and records nothing; the next poll's frame joins once. */
    public function testFailedJoinRollsBackAndTheNextPollJoinsOnce(): void
    {
        [$operation, $handover, $ask, $frame] = $this->personalFrameSent();
        $personalReceive = $this->duty($operation, AccountMergeDuty::PersonalReceive);
        $this->failJoin = true;
        $this->deliver($frame);
        [$failed] = $this->frames();
        self::assertSame([$personalReceive->id, AccountOperationAnswerKind::Failed],
            [$failed->data->data->assignmentId, $failed->data->data->answer]);
        self::assertSame([], $this->resultsOf($personalReceive));
        self::assertSame(['survivor note'], $this->labelsOf(self::SURVIVOR));
        self::assertSame(['loser note', 'loser wish'], $this->labelsOf(self::LOSER));
        $this->deliver($failed);
        self::assertSame([], $this->frames());

        $this->failJoin = false;
        $this->deliver($ask);
        [$again] = $this->personalFrames($this->frames());
        $this->deliver($again);
        [$joined] = $this->frames();
        self::assertSame(AccountOperationAnswerKind::Done, $joined->data->data->answer);
        self::assertSame(['{"rows":{"items":2}}'], $this->resultsOf($personalReceive));
        self::assertSame(['survivor note', 'loser note', 'loser wish'], $this->labelsOf(self::SURVIVOR));
        self::assertSame(2, $this->joins);
        $this->deliver($joined);
        $this->deliver($handover);
        $this->finishExchange($operation);
        self::assertSame(['survivor note', 'loser note', 'loser wish'], $this->labelsOf(self::SURVIVOR));
        self::assertSame([], $this->labelsOf(self::LOSER));
    }

    /** (e) A delete that throws rolls back and leaves the loser's rows; the repeated poll deletes once. */
    public function testFailedDeleteRollsBackAndTheRepeatedPollDeletesOnce(): void
    {
        [$operation, $handover, $ask, $frame] = $this->personalFrameSent();
        $personalGive = $this->duty($operation, AccountMergeDuty::PersonalGive);
        $this->deliver($frame);
        [$joined] = $this->frames();
        $this->deliver($joined);
        $this->frames();
        $this->failDelete = true;
        $this->deliver($ask);
        [$failed] = $this->frames();
        self::assertSame([$personalGive->id, AccountOperationAnswerKind::Failed], [$failed->data->data->assignmentId, $failed->data->data->answer]);
        self::assertSame([], $this->resultsOf($personalGive));
        self::assertSame(['loser note', 'loser wish'], $this->labelsOf(self::LOSER));
        $this->deliver($failed);
        self::assertSame([], $this->frames());

        $this->failDelete = false;
        $this->deliver($ask);
        [$deleted] = $this->frames();
        self::assertSame(AccountOperationAnswerKind::Done, $deleted->data->data->answer);
        self::assertSame(['{"rows":{"items":2}}'], $this->resultsOf($personalGive));
        self::assertSame([], $this->labelsOf(self::LOSER));
        self::assertSame([1, 2], [$this->joins, $this->deletes]);
        $this->deliver($deleted);
        $this->deliver($handover);
        $this->finishExchange($operation);
        self::assertSame(['survivor note', 'loser note', 'loser wish'], $this->labelsOf(self::SURVIVOR));
    }

    /** @return iterable<string, array{string}> Forged frames: a sender other than the merge's loser, or an address other than the personal receive */
    public static function forgedFrames(): iterable
    {
        foreach (['stranger sender', 'survivor sender', 'framework receive duty', 'loser personal give duty', 'stranger survivor'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /**
     * (f) A frame from anyone but the loser, or addressed to another duty, is refused and writes nothing.
     *
     * @param string $defect What the forged frame changes
     */
    #[DataProvider('forgedFrames')]
    public function testForgedFrameIsRefusedAndWritesNothing(string $defect): void
    {
        [$operation, , , $frame] = $this->personalFrameSent();
        $personalReceive = $this->duty($operation, AccountMergeDuty::PersonalReceive);
        $fields = $frame->data->data->toArray();
        $sender = in_array($defect, ['stranger sender', 'survivor sender'], true);
        $source = $sender
            ? new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, $defect === 'stranger sender' ? '11' : '7')
            : $frame->signalSource;
        if ($defect === 'framework receive duty') {
            $fields['assignmentId'] = $this->duty($operation, AccountMergeDuty::Receive)->id;
        } elseif ($defect === 'loser personal give duty') {
            $fields['assignmentId'] = $this->duty($operation, AccountMergeDuty::PersonalGive)->id;
        } elseif ($defect === 'stranger survivor') {
            $fields['ownerIndex'] = '11';
        }
        $forged = new SignalDTO($source, $frame->signalType, $frame->signalName,
            new AgentSignalData(AccountMergePersonalHandoverSignalData::fromArray($fields)));
        $before = $this->operationFacts();
        if ($sender) {
            $this->refused(AgentException::class, fn () => $this->deliver($forged));
        } else {
            $this->deliver($forged);
            [$answer] = $this->frames();
            self::assertSame(AccountOperationAnswerKind::Refused, $answer->data->data->answer);
            $this->deliver($answer);
        }
        self::assertSame([], $this->frames());
        self::assertSame($before, $this->operationFacts());
        self::assertSame(0, $this->joins);

        $this->deliver($frame);
        [$joined] = $this->frames();
        self::assertSame([$personalReceive->id, AccountOperationAnswerKind::Done], [$joined->data->data->assignmentId, $joined->data->data->answer]);
    }

    /** (g) After cleanup stops the loser's agent, the agent raised again runs its personal give; ordinary writes stay closed. */
    public function testLoserRaisedAgainAfterCleanupContinuesItsPersonalGiveOnly(): void
    {
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        $held = $this->drainExcept(AccountMergeDuty::PersonalGive);
        self::assertCount(1, $this->resultsOf($this->duty($operation, AccountMergeDuty::Cleanup)));
        self::assertTrue($this->personAgent(self::LOSER)->shouldStop());

        $loser = $this->restartPerson(self::LOSER);
        ExecutionContext::run(new ExecutionFrame(agentId: $loser->getId()), static fn () => $loser->onStart());
        $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData(new UserRenameSignalData(
                self::LOSER, 'Renamed loser', self::LOSER, self::REPLY, 'merge-personal-part-browser', null,
                HilosSignalConstants::PROFILE_NAME_SET, null, null,
            )),
            '',
            HilosSignalConstants::HILOS_USER_RENAME,
        ));
        self::assertNotNull($this->renameAnswer()->error);
        self::assertSame('Loser', Database::sql('SELECT `name` FROM `hilos_user` WHERE `id` = ?', [self::LOSER])->row()['name']);

        $this->deliver($held[0]);
        [$frame, $waits] = $this->frames();
        self::assertInstanceOf(AccountMergePersonalHandoverSignalData::class, $frame->data->data);
        self::assertSame(AccountOperationAnswerKind::Waits, $waits->data->data->answer);
        $this->deliver($frame);
        $this->finishExchange($operation);
        self::assertSame([], $this->labelsOf(self::LOSER));
        self::assertSame(['survivor note', 'loser note', 'loser wish'], $this->labelsOf(self::SURVIVOR));
    }

    /** (h) With every other duty done, the merge ends neither before the survivor's result nor before the loser's. */
    public function testMergeEndsOnlyAfterBothPersonalResults(): void
    {
        [$operation, $give] = $this->readyGo();
        $personalGive = $this->duty($operation, AccountMergeDuty::PersonalGive);
        $personalReceive = $this->duty($operation, AccountMergeDuty::PersonalReceive);
        $this->deliver($give);
        $held = $this->drainExcept(AccountMergeDuty::PersonalGive);
        self::assertSame([[], []], [$this->resultsOf($personalGive), $this->resultsOf($personalReceive)]);
        self::assertNull($this->row($operation)['finished_at']);
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $reentry = $this->frames();
        self::assertSame(['personal_give'], $this->keys($reentry));
        self::assertNull($this->row($operation)['finished_at']);

        $this->restartPerson(self::LOSER);
        $this->deliver($held[0]);
        [$frame, $waits] = $this->frames();
        $this->deliver($waits);
        $this->deliver($frame);
        [$joined] = $this->frames();
        self::assertSame(['{"rows":{"items":2}}'], $this->resultsOf($personalReceive));
        self::assertNull($this->row($operation)['finished_at']);
        $this->deliver($joined);
        [$deletion] = $this->frames();
        self::assertSame(['personal_give'], $this->keys([$deletion]));
        self::assertNull($this->row($operation)['finished_at']);

        $this->deliver($deletion);
        [$deleted] = $this->frames();
        self::assertNull($this->row($operation)['finished_at']);
        $this->deliver($deleted);
        self::assertSame('completed', $this->row($operation)['outcome']);
        self::assertNotNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
    }

    /**
     * (i) With the asks of the personal give lost, the loser's agent resumes it at start: the first start sends the frame,
     * the start after the survivor's result deletes, and the merge ends without the coordinator's reentry.
     */
    public function testLoserStartResumesItsLostPersonalGiveToTheEnd(): void
    {
        MergePersonalPartResumedHilos::initBrowser();
        [$operation, $give] = $this->readyGo();
        $personalGive = $this->duty($operation, AccountMergeDuty::PersonalGive);
        $personalReceive = $this->duty($operation, AccountMergeDuty::PersonalReceive);
        $this->deliver($give);
        self::assertNotSame([], $this->drainExcept(AccountMergeDuty::PersonalGive));
        self::assertSame([[], []], [$this->resultsOf($personalGive), $this->resultsOf($personalReceive)]);
        self::assertSame(['loser note', 'loser wish'], $this->labelsOf(self::LOSER));

        $this->startPerson(self::LOSER);
        [$frame, $waits] = $this->frames();
        self::assertSame(HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_PERSONAL_HANDOVER, $frame->signalName->getName());
        self::assertSame('agent/hilos_user#9', SignalSource::describe($frame->signalSource));
        self::assertSame([$personalReceive->id, '7', ['items' => [['label' => 'loser note'], ['label' => 'loser wish']]]],
            [$frame->data->data->assignmentId, $frame->data->data->ownerIndex, $frame->data->data->rows]);
        self::assertSame([$personalGive->id, AccountOperationAnswerKind::Waits],
            [$waits->data->data->assignmentId, $waits->data->data->answer]);
        self::assertSame([[], []], [$this->resultsOf($personalGive), $this->resultsOf($personalReceive)]);
        self::assertSame(['loser note', 'loser wish'], $this->labelsOf(self::LOSER));
        $this->deliver($waits);
        self::assertSame([], $this->frames());

        $this->deliver($frame);
        [$joined] = $this->frames();
        self::assertSame([$personalReceive->id, AccountOperationAnswerKind::Done],
            [$joined->data->data->assignmentId, $joined->data->data->answer]);
        // The ask that would delete is lost as well.
        $this->deliver($joined);
        self::assertSame(['personal_give'], $this->keys($this->frames()));
        self::assertSame(['loser note', 'loser wish'], $this->labelsOf(self::LOSER));
        self::assertNull($this->row($operation)['finished_at']);

        $this->startPerson(self::LOSER);
        [$deleted] = $this->frames();
        self::assertSame([$personalGive->id, AccountOperationAnswerKind::Done],
            [$deleted->data->data->assignmentId, $deleted->data->data->answer]);
        self::assertSame(['{"rows":{"items":2}}'], $this->resultsOf($personalGive));
        self::assertSame([], $this->labelsOf(self::LOSER));
        self::assertSame(['survivor note', 'loser note', 'loser wish'], $this->labelsOf(self::SURVIVOR));
        $this->deliver($deleted);
        self::assertSame('completed', $this->row($operation)['outcome']);
        self::assertNotNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        self::assertSame([1, 1], [$this->joins, $this->deletes]);
    }

    /**
     * The loser's agent reads its rows of the set, and only reads them.
     *
     * @param AccountMergePart $part Merge whose loser gives
     * @return array<string, mixed> The loser's labels under one family, nothing when it has none
     */
    private function giveRows(AccountMergePart $part): array
    {
        $rows = $this->personalRows();
        $labels = [];
        foreach (iterator_to_array($rows->loadByColumn(MergePersonalPartRow::user_id, $part->loserUserId), false) as $key) {
            $labels[] = ['label' => $rows[$key]->label];
        }

        return $labels === [] ? [] : ['items' => $labels];
    }

    /**
     * The survivor's agent writes the given labels into its own set, and throws after the writes when the case says.
     *
     * @param AccountMergePart $part Merge whose survivor joins
     * @param array<string, mixed> $given The loser's rows as the frame carried them
     * @return array<string, int> Labels joined, nothing when none came
     */
    private function joinRows(AccountMergePart $part, array $given): array
    {
        $this->joins++;
        $rows = $this->personalRows();
        foreach ($given['items'] ?? [] as $item) {
            $row = MergePersonalPartRowObject::create();
            $row->userId = $part->survivorUserId;
            $row->label = $item['label'];
            $row->sync();
            $rows[$row->getIdString()] = $row;
        }
        if ($this->failJoin) {
            throw new DatabaseException('The example could not join the given rows.');
        }

        return $given === [] ? [] : ['items' => count($given['items'])];
    }

    /**
     * The loser's agent deletes its rows of the set, and throws after the deletes when the case says.
     *
     * @param AccountMergePart $part Merge whose loser deletes
     * @return array<string, int> Rows deleted, nothing when none stood
     */
    private function deleteRows(AccountMergePart $part): array
    {
        $this->deletes++;
        $rows = $this->personalRows();
        $keys = iterator_to_array($rows->loadByColumn(MergePersonalPartRow::user_id, $part->loserUserId), false);
        foreach ($keys as $key) {
            $rows[$key]->delete();
        }
        if ($this->failDelete) {
            throw new DatabaseException('The example could not delete the given rows.');
        }

        return $keys === [] ? [] : ['items' => count($keys)];
    }

    /** @return UserRenameDoneSignalData The one answer to the case's rename, taken off the queue with everything before it */
    private function renameAnswer(): UserRenameDoneSignalData
    {
        $answers = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === self::REPLY) {
                self::assertInstanceOf(AgentSignalData::class, $frame->data);
                self::assertInstanceOf(UserRenameDoneSignalData::class, $frame->data->data);
                $answers[] = $frame->data->data;
            }
        }
        self::assertCount(1, $answers);

        return $answers[0];
    }

    /** @return MergePersonalPartRows The case's personal set, through the guarded entrance an agent reads through */
    private function personalRows(): MergePersonalPartRows
    {
        $rows = Hilos::$db->getObjectCollection(MergePersonalPartRows::COLLECTION_KEY);
        Assert::assertInstanceOf(MergePersonalPartRows::class, $rows);

        return $rows;
    }

    /**
     * @return array{AccountOperation, SignalDTO, SignalDTO, SignalDTO} Merge, held framework handover, personal give ask and
     *     the first personal frame, with the framework part left undelivered
     */
    private function personalFrameSent(): array
    {
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        [$handover, $giveAnswer] = $this->frames();
        $this->deliver($giveAnswer);
        [$ask] = $this->asksOf($this->frames(), AccountMergeDuty::PersonalGive);
        $this->deliver($ask);
        [$frame, $waits] = $this->frames();
        self::assertInstanceOf(AccountMergePersonalHandoverSignalData::class, $frame->data->data);
        $this->deliver($waits);
        self::assertSame([], $this->frames());

        return [$operation, $handover, $ask, $frame];
    }

    /**
     * @param int $userId Person whose agent the worker raises again
     * @return AbstractUserAgent A new agent of the person under its claims, not started; the stopped one is dropped
     */
    private function restartPerson(int $userId): AbstractUserAgent
    {
        $previous = $this->personAgents[$userId] ?? null;
        if ($previous !== null) {
            TruthSourceRegistry::unregisterAgent($previous->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($previous->getId()));
            unset($this->personAgents[$userId]);
        }

        return $this->personAgent($userId);
    }

    /** @param int $userId Person whose agent the worker raises again, starts and resumes in its real worker identity */
    private function startPerson(int $userId): void
    {
        $agent = $this->restartPerson($userId);
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static function () use ($agent): void {
            $agent->onStart();
            AccountOperationAsks::resume($agent, $agent->getType(), $agent->getIndex());
        });
        self::assertFalse(Database::hasOpenTransactions());
    }

    /**
     * @param AccountOperation $operation Merge whose go duties are read
     * @return array<string, string> Go duty keys in recording order, each with its owner type and index
     */
    private function goDuties(AccountOperation $operation): array
    {
        $duties = [];
        foreach (Database::sql('SELECT `assignment_key`, `owner_type`, `owner_index` FROM `hilos_account_operation_assignment`'
            . ' WHERE `operation_id` = ? AND `stage` = ? ORDER BY `id`', [$operation->id, AccountMergeStage::Go->value])->rows() as $row) {
            $duties[$row['assignment_key']] = $row['owner_type'] . '#' . $row['owner_index'];
        }

        return $duties;
    }

    /**
     * @param AccountOperation $operation Merge whose duty is read
     * @param AccountMergeDuty $duty Go duty
     * @return AccountOperationAssignment The recorded duty
     */
    private function duty(AccountOperation $operation, AccountMergeDuty $duty): AccountOperationAssignment
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Go->value, $duty->value);
        self::assertNotNull($assignment);

        return $assignment;
    }

    /**
     * @param AccountOperationAssignment $assignment Duty whose results are read
     * @return list<string> Its saved payloads, re-encoded compactly, as a separate SQL read sees them
     */
    private function resultsOf(AccountOperationAssignment $assignment): array
    {
        return array_map(static fn (array $row): string => json_encode(
            json_decode((string)$row['payload_json'], flags: JSON_THROW_ON_ERROR),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ), Database::sql('SELECT `payload_json` FROM `hilos_account_operation_result` WHERE `assignment_id` = ? ORDER BY `id`',
            [$assignment->id])->rows());
    }

    /**
     * @param int $userId Person whose rows of the set are read
     * @return list<string> Their labels in id order
     */
    private function labelsOf(int $userId): array
    {
        return array_column(Database::sql('SELECT `label` FROM `' . MergePersonalPartRow::_table . '` WHERE `user_id` = ? ORDER BY `id`',
            [$userId])->rows(), 'label');
    }

    /** @return array<string, list<array<string, mixed>>> Durable facts a refused frame must not change */
    private function operationFacts(): array
    {
        $facts = [];
        foreach (['hilos_account_operation', 'hilos_account_operation_assignment', 'hilos_account_operation_result',
            MergePersonalPartRow::_table] as $table) {
            $facts[$table] = Database::sql('SELECT * FROM `' . $table . '` ORDER BY `id`')->rows();
        }

        return $facts;
    }

    /**
     * @param AccountMergeDuty $duty Duty whose asks are withheld from the finite real exchange
     * @return list<SignalDTO> Held asks, with no synthetic result writes
     */
    private function drainExcept(AccountMergeDuty $duty): array
    {
        $held = [];
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                self::assertNotSame([], $held);

                return $held;
            }
            foreach ($frames as $frame) {
                if ($this->asksOf([$frame], $duty) !== []) {
                    $held[] = $frame;
                } else {
                    $this->deliver($frame);
                }
            }
        }
        self::fail('The held exchange must settle without a retry loop.');
    }

    /**
     * @param AccountOperation $operation Merge whose queued real handlers must settle without manual advance
     * @return list<SignalDTO> Every frame delivered on the way, in delivery order
     */
    private function finishExchange(AccountOperation $operation): array
    {
        $delivered = [];
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                self::assertSame('completed', $this->row($operation)['outcome']);
                self::assertNotNull($this->row($operation)['finished_at']);
                self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());

                return $delivered;
            }
            foreach ($frames as $frame) {
                $this->deliver($frame);
                $delivered[] = $frame;
            }
        }
        self::fail('The finite handler exchange must settle without replay loops.');
    }

    /** @return array{AccountOperation, SignalDTO} Real preparation exchange, stopping before the queued give */
    private function readyGo(): array
    {
        $operation = $this->asHolder(fn () => $this->holder->openMerge(
            self::SURVIVOR, self::LOSER, new AccountMergeChoice(null, null, null, null),
        ));
        foreach ($this->frames() as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        [$give] = $this->frames();
        self::assertSame([AccountMergeDuty::Give->value], $this->keys([$give]));

        return [$operation, $give];
    }

    /** @return list<SignalDTO> Real queued envelopes of the exchange, excluding unrelated sync announcements */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData || $frame->data->data instanceof AccountOperationAnswerSignalData
                    || $frame->data->data instanceof AccountMergeHandoverSignalData
                    || $frame->data->data instanceof AccountMergePersonalHandoverSignalData)) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /**
     * @param list<SignalDTO> $frames Frames taken off the queue
     * @return list<SignalDTO> The personal frames among them, in queue order
     */
    private function personalFrames(array $frames): array
    {
        return array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->data->data instanceof AccountMergePersonalHandoverSignalData));
    }

    /**
     * @param list<SignalDTO> $frames Frames taken off the queue
     * @param AccountMergeDuty $duty Duty whose asks are looked for
     * @return list<SignalDTO> The asks of that duty, in queue order
     */
    private function asksOf(array $frames, AccountMergeDuty $duty): array
    {
        return array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->data->data instanceof AccountOperationAskSignalData
            && Hilos::$db->accountOperationAssignments[$frame->data->data->assignmentId]->assignmentKey === $duty->value));
    }

    /**
     * @param list<SignalDTO> $frames Frames taken off the queue
     * @return list<SignalDTO> Every frame but the asks of the personal give, in queue order
     */
    private function exceptPersonalGive(array $frames): array
    {
        return array_values(array_filter($frames, fn (SignalDTO $frame): bool => $this->asksOf([$frame], AccountMergeDuty::PersonalGive) === []));
    }

    /** @param SignalDTO $queued Real frame, serialized and topology-hydrated as it is at worker delivery */
    private function deliver(SignalDTO $queued): void
    {
        $frame = SignalDTO::fromArray(json_decode(json_encode($queued->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        $data = Hilos::$sr->createAgentSignalPayloadDTO($frame->signalName->getName(), $frame->data);
        $sender = SignalSource::describe($frame->signalSource);
        if ($data->data instanceof AccountOperationAnswerSignalData) {
            $this->asHolder(fn () => $this->holder->onSignalAgent($data, $sender, $frame->signalName->getName()));

            return;
        }
        self::assertTrue($data->data instanceof AccountOperationAskSignalData || $data->data instanceof AccountMergeHandoverSignalData
            || $data->data instanceof AccountMergePersonalHandoverSignalData);
        $this->asPerson((int)$data->data->ownerIndex, static fn (AbstractUserAgent $agent) =>
            $agent->onSignalAgent($data, $sender, $frame->signalName->getName()));
    }

    /**
     * @param list<SignalDTO> $frames Frames carrying an assignment id
     * @return list<string> Stored assignment keys in transport order
     */
    private function keys(array $frames): array
    {
        return array_map(static fn (SignalDTO $frame): string =>
            Hilos::$db->accountOperationAssignments[$frame->data->data->assignmentId]->assignmentKey, $frames);
    }

    /**
     * @template T
     * @param callable(): T $work Work owned by the canonical session holder
     * @return T Work result
     */
    private function asHolder(callable $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), $work);
    }

    /**
     * @param AccountOperation $operation Merge to observe independently of its cached facade
     * @return array<string, mixed> Persisted operation row
     */
    private function row(AccountOperation $operation): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id])->row();
    }

    /** Mounts the framework collections and the case's personal set without changing the parent configuration. */
    private function freshContext(): void
    {
        Hilos::$db = new MergePersonalPartContext();
        Hilos::$db->configure();
    }

    /**
     * @param class-string<Throwable> $expected Expected failure category
     * @param callable $work Refused operation
     */
    private function refused(string $expected, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $failure) {
            self::assertInstanceOf($expected, $failure);

            return;
        }
        self::fail('The forged frame must be refused.');
    }

    /** @param bool $down Whether to drop the supporting tables in reverse dependency order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::EXTRA_TABLES) : self::EXTRA_TABLES as $table) {
            // external-boundary: migration stub filenames carry the direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_'
                . $table . $suffix . '.sql'));
        }
    }
}

/** Thin access to the session holder's protected merge entries; transport and dispatch are inherited. */
final class MergePersonalPartHolder extends AbstractSessionsLibraryAgent
{
    /**
     * @param int $survivor Survivor account
     * @param int $loser Loser account
     * @param AccountMergeChoice $choice Immutable choices
     * @return AccountOperation Prepared opening
     */
    public function openMerge(int $survivor, int $loser, AccountMergeChoice $choice): AccountOperation
    {
        return $this->openAccountMerge($survivor, $loser, $choice);
    }

    /** @param AccountOperation $operation Prepared merge to reenter */
    public function driveMerge(AccountOperation $operation): void
    {
        $this->driveAccountMerge($operation);
    }
}

/**
 * The project's agent of one person in the case: it holds the person's rows of the case's set and
 * joins them in a merge through the case's closures. The personal frame must leave outside any
 * transaction.
 */
final class MergePersonalPartUser extends AbstractUserAgent implements AccountMergePersonalJoin
{
    /** @var array<string, list<TruthSourceOperation>> The framework's sets of the person, and the person's rows of the case */
    public const array OWNS_DB_SET = [
        ...parent::OWNS_DB_SET,
        MergePersonalPartRows::COLLECTION_KEY => TruthSourceOperation::ALL,
    ];

    /**
     * @param string $agentIndex Person id from the agent address
     * @param Closure(AccountMergePart): array<string, mixed> $give Reads the loser's rows
     * @param Closure(AccountMergePart, array<string, mixed>): array<string, int> $join Writes the given rows into the survivor's set; may throw
     * @param Closure(AccountMergePart): array<string, int> $delete Deletes the loser's rows; may throw
     */
    public function __construct(
        string $agentIndex,
        private readonly Closure $give,
        private readonly Closure $join,
        private readonly Closure $delete,
    ) {
        parent::__construct($agentIndex);
    }

    /**
     * @param AccountMergePart $part Operation, survivor and loser of the merge
     * @return array<string, mixed> What the case's give closure reads
     */
    public function personalRowsToGive(AccountMergePart $part): array
    {
        return ($this->give)($part);
    }

    /**
     * @param AccountMergePart $part Operation, survivor and loser of the merge
     * @param array<string, mixed> $given The loser's rows as the frame carried them
     * @return array<string, int> What the case's join closure wrote
     */
    public function joinPersonalRows(AccountMergePart $part, array $given): array
    {
        return ($this->join)($part, $given);
    }

    /**
     * @param AccountMergePart $part Operation, survivor and loser of the merge
     * @return array<string, int> What the case's delete closure deleted
     */
    public function deleteGivenPersonalRows(AccountMergePart $part): array
    {
        return ($this->delete)($part);
    }

    /**
     * @param string $signalName Real routed frame name
     * @param SignalDataInterface $data Typed transport payload
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        if ($signalName === HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_PERSONAL_HANDOVER) {
            Assert::assertFalse(Database::hasOpenTransactions(), 'The personal frame must leave outside any transaction.');
        }
        parent::sendToAgent($signalName, $data);
    }
}

/** The installation of the case: the person's agent joins its personal set, which the directory declares. */
abstract class MergePersonalPartHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = MergePersonalPartDirectory::class;
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => MergePersonalPartUser::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => MergePersonalPartHolder::class],
    ];
}

/** The same set declared, with the framework's person agent that does not join it. */
abstract class MergePersonalPartWithoutJoinHilos extends MergePersonalPartHilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => IntegrationUserAgent::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => MergePersonalPartHolder::class],
    ];
}

/** The installation of the case with sign-in built in, so a person's agent resumes its owed duties at start. */
abstract class MergePersonalPartResumedHilos extends MergePersonalPartHilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}

/** The joining person agent, with a directory that declares no personal set. */
abstract class MergePersonalPartUndeclaredHilos extends MergePersonalPartHilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountDataOwnerDirectory::class;
}

/** Declares the case's rows as the person's agent's own. */
abstract class MergePersonalPartDirectory extends AccountDataOwnerDirectory
{
    /** @return list<string> The case's personal set */
    protected static function personal(): array
    {
        return [MergePersonalPartRows::COLLECTION_KEY];
    }
}

/** The framework's collections and the case's personal set. */
final class MergePersonalPartContext extends HilosDbContext
{
    /** Mounts the framework first, then the personal set. */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(MergePersonalPartRows::class, Objects::LAZY_STRATEGY_KEY);
    }
}

/** A labelled row of one person's set in the case. */
final class MergePersonalPartRow extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string label = 'label';

    public const string _table = 'hilos_fw_test_merge_personal_row';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::user_id, self::label];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::label => PhpType::STRING->value,
    ];
    // The row of a person, so it is in the person's set; the case's table carries no foreign key.
    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    public ?int $id = null;
    public int $user_id;
    public string $label;
}

/**
 * Stored row of the case's personal set.
 *
 * @extends Object_<MergePersonalPartRow>
 * @property int $userId
 * @property string $label
 */
final class MergePersonalPartRowObject extends Object_
{
    public const string ENTITY_CLASS = MergePersonalPartRow::class;
    public const string OBJECT_COLLECTION_CLASS = MergePersonalPartRows::class;
    public const string userId = 'userId';
    public const string label = 'label';

    /**
     * @param string $property Stored field name
     * @return mixed Stored value
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::userId => $this->entity->user_id,
            self::label => $this->entity->label,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Stored field name
     * @param mixed $value New value
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::label => $this->entity->label = (string)$value,
            default => parent::__set($property, $value),
        };
    }
}

/**
 * @extends Objects<MergePersonalPartRowObject>
 */
final class MergePersonalPartRows extends Objects
{
    public const string OBJECT_CLASS = MergePersonalPartRowObject::class;
    public const string COLLECTION_KEY = 'mergePersonalPartRows';
}
