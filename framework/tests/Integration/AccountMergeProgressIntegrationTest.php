<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeReceiveResult;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountMergeProgress;
use Hilos\Users\AccountMergeProgressChangeSubscriber;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\Users\AccountMergeRefusalText;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

require_once __DIR__ . '/AccountMergeCoordinatorIntegrationTest.php';

/** Real owner commits and transport boundaries observed only through the saved read model. */
final class AccountMergeProgressIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int SURVIVOR = 7;
    private const int LOSER = 9;
    private const array EXTRA_TABLES = ['hilos_passkey_credential', 'hilos_account_merge_copy'];
    private MergeCoordinatorHolder $holder;
    private ?MergeCoordinatorProjectOwner $project = null;
    private string $previousApp;
    private ?SignalRouter $previousRouter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->stubs(true);
        $this->stubs(false);
        Database::sqlRun('CREATE TABLE `' . AccountMergeProjectNote::_table . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `user_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`id`))');
        Schema::reset();
        Schema::initialize();
        $this->freshContext();
        MergeCoordinatorHilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new AccountMergeProgressChangeSubscriber());
        AccountMergeProgressResolver::forgetAll();
        $this->holder = new MergeCoordinatorHolder();
        OwnershipDeclaration::claimAll($this->holder);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser'), (11, 'Other'), (13, 'Peer')");
    }

    protected function tearDown(): void
    {
        AccountMergeProgressResolver::forgetAll();
        try {
            Database::handlerEnd();
            Database::sqlRun('DROP TABLE IF EXISTS `' . AccountMergeProjectNote::_table . '`');
            $this->stubs(true);
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent($this->holder->getId());
            RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
            if ($this->project !== null) {
                TruthSourceRegistry::unregisterAgent($this->project->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->project->getId()));
            }
            Hilos::$sr = $this->previousRouter;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    public function testPreparingShowsOnlyRecordedDutiesAndEachRealPreparationAnswer(): void
    {
        self::assertNull(AccountMergeProgressResolver::of(self::SURVIVOR));
        $operation = $this->open();
        $pending = AccountMergeProgressResolver::of(self::SURVIVOR);
        self::assertSame($pending, AccountMergeProgressResolver::forOperation($operation->id));
        self::assertSame('preparing', $pending->state);
        self::assertSame(['prepare_survivor', 'prepare_loser'], array_column($pending->parts, 'participant'));
        self::assertSame([false, false], array_column($pending->parts, 'done'));
        $asks = $this->frames();
        $this->deliver($asks[0]);
        [$first] = $this->frames();
        $one = AccountMergeProgressResolver::of(self::LOSER);
        self::assertSame([true, false], array_column($one->parts, 'done'));
        self::assertSame('preparing', $one->state);
        $this->deliver($asks[1]);
        [$second] = $this->frames();
        $two = AccountMergeProgressResolver::forOperation($operation->id);
        self::assertSame([true, true], array_column($two->parts, 'done'));
        self::assertSame('preparing', $two->state);
        $this->deliver($first);
        $this->deliver($second);
        $go = AccountMergeProgressResolver::of(self::SURVIVOR);
        self::assertSame('wait', $go->state);
        self::assertFalse($go->given);
        self::assertFalse($go->received);
        self::assertNull($go->outcome);
        self::assertCount(7, $go->parts);
    }

    public function testBusinessRefusalRemainsUnfinishedUntilBothRealReleases(): void
    {
        $operation = $this->open(new AccountMergeChoice(null, SecondFactorFate::SURVIVOR, true, false));
        foreach (array_reverse($this->frames()) as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        $refused = AccountMergeProgressResolver::forOperation($operation->id);
        self::assertSame('refusal', $refused->state);
        self::assertSame('cancelling', $refused->stage);
        self::assertNull($refused->outcome);
        self::assertSame(['code' => 'protection_changed',
            'reason' => 'Second-factor protection changed. Review the accounts and choose again.'], $refused->refusal);
        self::assertSame([true, true, false, false], array_column($refused->parts, 'done'));
        $releases = $this->frames();
        $this->deliver($releases[0]);
        $this->deliver($this->frames()[0]);
        self::assertNull(AccountMergeProgressResolver::of(self::SURVIVOR)->outcome);
        $this->deliver($releases[1]);
        $this->deliver($this->frames()[0]);
        $closed = AccountMergeProgressResolver::of(self::LOSER);
        self::assertSame('refusal', $closed->state);
        self::assertSame('not_started', $closed->outcome);
        self::assertSame([true, true, true, true], array_column($closed->parts, 'done'));
    }

    public function testGiveWithoutSerializedReceiptIsOnlyWaitAndNeverExportsItsManifest(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'private-transfer@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        $progress = AccountMergeProgressResolver::forOperation($operation->id);
        self::assertSame('wait', $progress->state);
        self::assertTrue($progress->given);
        self::assertFalse($progress->received);
        self::assertNull($progress->outcome);
        $json = json_encode($progress->toArray(), JSON_THROW_ON_ERROR);
        foreach (['passwordIdentityId', 'secondFactorIds', 'identitiesMoved', 'admission', 'ownerAgentId',
            'ownerIndex', 'assignmentId', 'manifest', 'revision', 'private-transfer@example.test'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame($progress->toArray(), AccountMergeProgress::fromArray($progress->toArray())->toArray());
    }

    public function testReceiptAndCleanupCannotReplaceTheOriginalProjectResultOrDurableEnd(): void
    {
        [$operation, , $project, $cleanup] = $this->projectCleanupWindow();
        $receipt = AccountMergeProgressResolver::forOperation($operation->id);
        self::assertTrue($receipt->given);
        self::assertTrue($receipt->received);
        self::assertSame('wait', $receipt->state);
        self::assertNull($receipt->outcome);
        $expected = AccountOperationExpectedSet::of($operation->id)->expected()[0];
        self::assertSame('preparing', $expected->stage);
        $this->deliver($cleanup);
        $this->drainExcept([$expected->assignmentKey]);
        $afterCleanup = AccountMergeProgressResolver::of(self::LOSER);
        self::assertSame('wait', $afterCleanup->state);
        self::assertSame([false], array_column(array_values(array_filter($afterCleanup->parts,
            static fn (array $part): bool => $part['participant'] === AccountOperationExpectation::participantOf($expected->payload))), 'done'));
        $this->deliver($project);
        $this->finishExchange($operation);
        $end = AccountMergeProgressResolver::forOperation($operation->id);
        self::assertSame('end', $end->state);
        self::assertSame('completed', $end->outcome);
        self::assertNotContains(false, array_column($end->parts, 'done'));
        self::assertSame([], $end->waits);
        self::assertSame($end, AccountMergeProgressResolver::of(self::SURVIVOR));
        self::assertSame($end, AccountMergeProgressResolver::of(self::LOSER));
        $this->freshContext();
        AccountMergeProgressResolver::forgetAll();
        self::assertSame($end->toArray(), AccountMergeProgressResolver::forOperation($operation->id)->toArray());
    }

    public function testExplicitCancellationEndsWithoutABusinessRefusalAndLaterRetryWinsById(): void
    {
        $first = $this->open();
        $this->frames();
        $this->asHolder(fn () => $this->holder->cancelMerge($first));
        $this->finishCancellation($first, $this->frames());
        $closed = AccountMergeProgressResolver::of(self::SURVIVOR);
        self::assertSame('end', $closed->state);
        self::assertSame('not_started', $closed->outcome);
        self::assertNull($closed->refusal);
        $retry = $this->open();
        Database::sqlRun('UPDATE `hilos_account_operation` SET `created_at` = ? WHERE `id` = ?', [$first->createdAt, $retry->id]);
        $this->freshContext();
        AccountMergeProgressResolver::forgetAll();
        self::assertGreaterThan($first->id, $retry->id);
        self::assertSame($retry->id, AccountMergeProgressResolver::of(self::LOSER)->operationId);
        self::assertSame($first->id, AccountMergeProgressResolver::forOperation($first->id)->operationId);
        $this->frames();
        $this->asHolder(fn () => $this->holder->cancelMerge($retry));
        $this->finishCancellation($retry, $this->frames());
        self::assertSame($retry->id, AccountMergeProgressResolver::of(self::SURVIVOR)->operationId);
    }

    public function testForeignCircleMemberCannotDiscoverAnotherSavedPair(): void
    {
        $operation = $this->open();
        $valid = AccountMergeProgressResolver::forOperation($operation->id)->toArray();
        Database::sqlRun('UPDATE `hilos_account_operation` SET `circle_user_ids` = ? WHERE `id` = ?', ['[11,9,7]', $operation->id]);
        $this->freshContext();
        AccountMergeProgressResolver::forgetAll();
        self::assertNull(AccountMergeProgressResolver::of(11));
        foreach ([self::SURVIVOR, self::LOSER] as $userId) {
            self::assertSame($valid, AccountMergeProgressResolver::of($userId)->toArray());
            self::assertSame(AccountMergeProgressResolver::of($userId), AccountMergeProgressResolver::forOperation($operation->id));
        }
    }

    public function testTrueOpenPairKeepsGreatestIdAndPriorityOverForeignCircleAndNewerClosedMerge(): void
    {
        $older = $this->open(survivor: 11, loser: 13);
        $this->frames();
        $latest = $this->asHolder(fn () => Hilos::$db->accountOperations->actions->recordMerge(
            13, 11, new AccountMergeChoice(null, null, null, null)));
        $this->asHolder(fn () => $this->holder->driveMerge($latest));
        $this->frames();
        $foreign = $this->open();
        $this->frames();
        $closed = $this->asHolder(fn () => Hilos::$db->accountOperations->actions->recordMerge(
            11, self::SURVIVOR, new AccountMergeChoice(null, null, null, null)));
        $this->asHolder(fn () => $this->holder->driveMerge($closed));
        $this->frames();
        $this->asHolder(fn () => $this->holder->cancelMerge($closed));
        $this->finishCancellation($closed, $this->frames());
        self::assertGreaterThan($older->id, $latest->id);
        self::assertGreaterThan($latest->id, $foreign->id);
        self::assertGreaterThan($foreign->id, $closed->id);
        Database::sqlRun('UPDATE `hilos_account_operation` SET `circle_user_ids` = ? WHERE `id` = ?', ['[11,9,7]', $foreign->id]);
        $this->freshContext();
        AccountMergeProgressResolver::forgetAll();
        foreach ([11, 13] as $userId) {
            self::assertSame($latest->id, AccountMergeProgressResolver::of($userId)->operationId);
            self::assertSame(AccountMergeProgressResolver::of($userId), AccountMergeProgressResolver::forOperation($latest->id));
        }
        foreach ([self::SURVIVOR, self::LOSER] as $userId) {
            self::assertSame($foreign->id, AccountMergeProgressResolver::of($userId)->operationId);
        }
        self::assertSame($older->id, AccountMergeProgressResolver::forOperation($older->id)->operationId);
        self::assertSame('not_started', AccountMergeProgressResolver::forOperation($closed->id)->outcome);
        $open = AccountMergeProgressResolver::open();
        self::assertSame([$older->id, $latest->id, $foreign->id], array_keys($open));
        foreach ($open as $operationId => $progress) {
            self::assertSame(AccountMergeProgressResolver::forOperation($operationId), $progress);
        }
    }

    public function testForeignResultsMalformedPayloadsAndCanonicalAssignmentMismatchesFailClosed(): void
    {
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        $giveAssignment = Hilos::$db->accountOperationAssignments[$give->data->data->assignmentId];
        $saved = Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$giveAssignment->id])->row();
        foreach (['owner_agent_id' => HilosAgentType::HILOS_USER . ':11', 'payload_json' => '{}'] as $column => $broken) {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `' . $column . '` = ? WHERE `id` = ?', [$broken, $saved['id']]);
            $this->freshContext();
            AccountMergeProgressResolver::forgetAll();
            $failure = $this->refused(InvalidFormatException::class, fn () => AccountMergeProgressResolver::forOperation($operation->id));
            self::assertSame($failure, $this->refused(InvalidFormatException::class,
                fn () => AccountMergeProgressResolver::forOperation($operation->id)));
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `' . $column . '` = ? WHERE `id` = ?', [$saved[$column], $saved['id']]);
        }
        Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `user_id` = 11 WHERE `id` = ?', [$giveAssignment->id]);
        $this->freshContext();
        AccountMergeProgressResolver::forgetAll();
        $this->refused(InvalidFormatException::class, fn () => AccountMergeProgressResolver::of(self::SURVIVOR));
        Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `user_id` = ? WHERE `id` = ?', [self::LOSER, $giveAssignment->id]);
        $this->freshContext();
        SourceChangeBus::publish(SourceChange::dbCleared(HilosDbContext::accountOperationResults));
        self::assertTrue(AccountMergeProgressResolver::of(self::LOSER)->given);
        self::assertFalse(AccountMergeProgressResolver::of(self::LOSER)->received);
        self::assertSame('wait', AccountMergeProgressResolver::of(self::LOSER)->state);
    }

    public function testMalformedPairStageAndTerminalOutcomeCannotBecomeASuccessfulEnd(): void
    {
        $operation = $this->open();
        $saved = $this->row($operation);
        foreach ([
            ['root_user_id' => 0],
            ['root_user_id' => self::LOSER],
            ['stage' => 'unknown'],
            ['outcome' => 'unknown', 'finished_at' => 1],
            ['outcome' => 'completed'],
            ['finished_at' => 1],
            ['outcome' => 'completed', 'finished_at' => 1],
            ['stage' => 'go', 'outcome' => 'not_started', 'finished_at' => 1],
        ] as $changes) {
            $set = implode(', ', array_map(static fn (string $column): string => '`' . $column . '` = ?', array_keys($changes)));
            Database::sqlRun('UPDATE `hilos_account_operation` SET ' . $set . ' WHERE `id` = ?', [...array_values($changes), $operation->id]);
            $this->freshContext();
            SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::accountOperations, (string)$operation->id, $changes));
            $failure = $this->refused(InvalidFormatException::class, fn () => AccountMergeProgressResolver::forOperation($operation->id));
            self::assertSame($failure, $this->refused(InvalidFormatException::class,
                fn () => AccountMergeProgressResolver::forOperation($operation->id)));
            $this->refused(isset($changes['root_user_id']) ? ValidationException::class : InvalidFormatException::class,
                fn () => AccountMergeProgressResolver::of(self::LOSER));
            $restored = array_replace($changes, array_intersect_key($saved, $changes));
            Database::sqlRun('UPDATE `hilos_account_operation` SET ' . $set . ' WHERE `id` = ?',
                [...array_values($restored), $operation->id]);
            SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::accountOperations, (string)$operation->id, $restored));
            self::assertSame('preparing', AccountMergeProgressResolver::forOperation($operation->id)->state);
        }
    }

    public function testAnErasureAndMissingPrimaryKeyDoNotBecomeMergeProgress(): void
    {
        $erasure = $this->asHolder(fn () => Hilos::$db->accountOperations->actions->recordErasure(
            81, self::SURVIVOR, AccountOperationCircle::fromArray([self::SURVIVOR], self::SURVIVOR)));
        self::assertNull(AccountMergeProgressResolver::forOperation($erasure->id));
        self::assertNull(AccountMergeProgressResolver::forOperation($erasure->id + 1));
        self::assertNull(AccountMergeProgressResolver::of(self::SURVIVOR));
    }

    public function testLocalWriteRollbackAndSourceClearDropBothDerivedIdentities(): void
    {
        $operation = $this->open();
        $before = AccountMergeProgressResolver::of(self::SURVIVOR);
        $inside = null;
        $this->refused(LogicException::class, function () use ($operation, &$inside): void {
            $this->asHolder(function () use ($operation, &$inside): void {
                Database::transaction(DatabaseNames::PRIMARY, function () use ($operation, &$inside): void {
                    self::assertTrue($operation->actions->advance('preparing', 'cancelling'));
                    $inside = AccountMergeProgressResolver::forOperation($operation->id);
                    self::assertSame('wait', $inside->state);
                    throw new LogicException('Rollback the observed transition.');
                });
            });
        });
        $restored = AccountMergeProgressResolver::of(self::SURVIVOR);
        self::assertSame($before->toArray(), $restored->toArray());
        self::assertNotSame($before, $restored);
        self::assertNotSame($inside, $restored);
        self::assertSame($restored, AccountMergeProgressResolver::forOperation($operation->id));
        foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments,
            HilosDbContext::accountOperationResults] as $source) {
            SourceChangeBus::publish(SourceChange::dbCleared($source));
            $next = AccountMergeProgressResolver::forOperation($operation->id);
            self::assertNotSame($restored, $next);
            self::assertSame($restored->toArray(), $next->toArray());
            $restored = $next;
        }
    }

    public function testTransientDatabaseFailureIsRetriedWithoutASourceMutation(): void
    {
        $operation = $this->open();
        Database::sqlRun('RENAME TABLE `hilos_account_operation` TO `hilos_account_operation_unavailable`');
        try {
            $this->refused(DatabaseException::class, fn () => AccountMergeProgressResolver::forOperation($operation->id));
            $this->refused(DatabaseException::class, fn () => AccountMergeProgressResolver::of(self::SURVIVOR));
        } finally {
            Database::sqlRun('RENAME TABLE `hilos_account_operation_unavailable` TO `hilos_account_operation`');
        }
        self::assertSame($operation->id, AccountMergeProgressResolver::of(self::SURVIVOR)->operationId);
    }

    public function testCapturedTwoPasswordsGiveOnlyTheExistingDerivedRefusalSentence(): void
    {
        $operation = $this->open();
        $asks = $this->frames();
        self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'survivor@example.test');
        self::seedIdentity(self::LOSER, IdentityType::PASSWORD, 'loser@example.test');
        $this->freshContext();
        foreach ($asks as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        $progress = AccountMergeProgressResolver::forOperation($operation->id);
        self::assertSame(['code' => 'password_fate_required',
            'reason' => 'Both accounts have a password: pass --password=survivor|loser|none'], $progress->refusal);
        self::assertSame('refusal', $progress->state);
        self::assertNull($progress->choice->passwordFate);
    }

    public function testLabelsUseCurrentNamesAndLiveMergeChainsWithSelectiveSourceInvalidation(): void
    {
        $operation = $this->open();
        $before = AccountMergeProgressResolver::of(self::SURVIVOR);
        self::assertSame('Survivor (#7)', AccountMergeRefusalText::accountLabel(self::SURVIVOR));
        self::assertSame('#81', AccountMergeRefusalText::accountLabel(81));
        Database::sqlRun('UPDATE `hilos_user` SET `name` = ? WHERE `id` = ?', ['Renamed', self::SURVIVOR]);
        $this->freshContext();
        SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::users, '7', ['last_activity_at' => 123]));
        self::assertSame($before, AccountMergeProgressResolver::of(self::SURVIVOR));
        SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::users, '7', ['name' => 'Renamed']));
        self::assertNotSame($before, AccountMergeProgressResolver::of(self::SURVIVOR));
        self::assertSame('Renamed (#7)', AccountMergeRefusalText::accountLabel(self::SURVIVOR));
        Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (11, 13, 1), (13, 7, 1)');
        $this->freshContext();
        SourceChangeBus::publish(SourceChange::dbCleared(HilosDbContext::userMerges));
        self::assertSame('Renamed (#7)', AccountMergeRefusalText::targetLabel(11));
        self::assertSame($operation->id, AccountMergeProgressResolver::of(self::SURVIVOR)->operationId);
    }

    public function testSavedRefusalTextRecoversAfterNameAndMergeChainMutationsWithoutRepeatingPreparation(): void
    {
        $operation = $this->open();
        $asks = $this->frames();
        Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (9, 11, 1)');
        $this->freshContext();
        foreach ($asks as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        $first = AccountMergeProgressResolver::forOperation($operation->id);
        self::assertSame('Loser (#9) was already merged into Other (#11)', $first->refusal['reason']);
        Database::sqlRun('UPDATE `hilos_user` SET `name` = ? WHERE `id` = 11', ['Renamed target']);
        $this->freshContext();
        SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::users, '11', ['name' => 'Renamed target']));
        $renamed = AccountMergeProgressResolver::forOperation($operation->id);
        self::assertNotSame($first, $renamed);
        self::assertSame('Loser (#9) was already merged into Renamed target (#11)', $renamed->refusal['reason']);
        Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (11, 13, 1)');
        $this->freshContext();
        SourceChangeBus::publish(SourceChange::dbCreated(HilosDbContext::userMerges, '11', []));
        self::assertSame('Loser (#9) was already merged into Peer (#13)',
            AccountMergeProgressResolver::forOperation($operation->id)->refusal['reason']);
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = 13');
        $this->freshContext();
        SourceChangeBus::publish(SourceChange::dbDeleted(HilosDbContext::users, '13'));
        self::assertSame('Loser (#9) was already merged into another account',
            AccountMergeProgressResolver::forOperation($operation->id)->refusal['reason']);
    }

    public function testEveryRealFixedAndCarriedProjectProofRequiresItsOwnOwnerAndStrictPayloadEvenAfterEnd(): void
    {
        $this->activateProject();
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        $this->finishExchange($operation);
        self::assertSame('completed', AccountMergeProgressResolver::forOperation($operation->id)->outcome);
        $this->assertPoisonedResultsRefuse($operation);
    }

    public function testBothRealReleaseProofsRequireTheirOwnBindingsAndStrictPayloadAfterCancellation(): void
    {
        $operation = $this->open();
        $this->frames();
        $this->asHolder(fn () => $this->holder->cancelMerge($operation));
        $this->finishCancellation($operation, $this->frames());
        self::assertSame('not_started', AccountMergeProgressResolver::forOperation($operation->id)->outcome);
        $this->assertPoisonedResultsRefuse($operation);
    }

    /** @param AccountOperation $operation Terminal operation completed by the real owner exchange */
    private function assertPoisonedResultsRefuse(AccountOperation $operation): void
    {
        $valid = AccountMergeProgressResolver::forOperation($operation->id)->toArray();
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            $saved = Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$assignment->id])->row();
            if ($saved === null) {
                continue;
            }
            foreach (['owner_agent_id' => HilosAgentType::HILOS_USER . ':11', 'payload_json' => '{}'] as $column => $broken) {
                Database::sqlRun('UPDATE `hilos_account_operation_result` SET `' . $column . '` = ? WHERE `id` = ?', [$broken, $saved['id']]);
                $this->freshContext();
                AccountMergeProgressResolver::forgetAll();
                $this->refused(InvalidFormatException::class, fn () => AccountMergeProgressResolver::forOperation($operation->id));
                Database::sqlRun('UPDATE `hilos_account_operation_result` SET `' . $column . '` = ? WHERE `id` = ?',
                    [$saved[$column], $saved['id']]);
            }
            $stored = Database::sql('SELECT * FROM `hilos_account_operation_assignment` WHERE `id` = ?', [$assignment->id])->row();
            foreach (['stage' => 'invalid', 'user_id' => 11, 'owner_type' => 'foreign', 'payload_json' => '{"extra":true}'] as $column => $broken) {
                Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `' . $column . '` = ? WHERE `id` = ?', [$broken, $assignment->id]);
                $this->freshContext();
                AccountMergeProgressResolver::forgetAll();
                $this->refused(InvalidFormatException::class, fn () => AccountMergeProgressResolver::forOperation($operation->id));
                Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `' . $column . '` = ? WHERE `id` = ?',
                    [$stored[$column], $assignment->id]);
            }
        }
        $this->freshContext();
        AccountMergeProgressResolver::forgetAll();
        self::assertSame($valid, AccountMergeProgressResolver::forOperation($operation->id)->toArray());
    }

    /** @return iterable<string, array{string, string, string}> Real preparation refusals and exact existing sentences */
    public static function savedRefusals(): iterable
    {
        yield 'missing loser' => ['missing-loser', 'missing', 'No such user: 9'];
        yield 'missing both' => ['missing-both', 'missing', 'No such user: 7'];
        yield 'merged survivor' => ['merged-survivor', 'survivor_merged', 'Survivor (#7) was itself merged into Other (#11)'];
        yield 'merged loser' => ['merged-loser', 'loser_merged', 'Loser (#9) was already merged into Other (#11)'];
        yield 'deleting survivor' => ['deleting-survivor', 'being_deleted', 'Survivor (#7) is being deleted'];
        yield 'deleting loser' => ['deleting-loser', 'being_deleted', 'Loser (#9) is being deleted'];
        yield 'deleting both' => ['deleting-both', 'being_deleted', 'Survivor (#7) is being deleted'];
        yield 'occupied survivor' => ['occupied-survivor', 'occupied', 'Survivor (#7) is already being merged'];
        yield 'occupied loser' => ['occupied-loser', 'occupied', 'Loser (#9) is already being merged'];
    }

    /**
     * @param string $scenario Fact changed after opening, before the personal preparations
     * @param string $code Existing priority-selected refusal
     * @param string $sentence Exact template arguments, survivor first for matching codes
     */
    #[DataProvider('savedRefusals')]
    public function testRefusalWordsUseTheExactSavedSideAndCurrentLabels(string $scenario, string $code, string $sentence): void
    {
        $operation = $this->open();
        $asks = $this->frames();
        $userId = str_ends_with($scenario, 'loser') ? self::LOSER : self::SURVIVOR;
        if (str_starts_with($scenario, 'missing-')) {
            Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$userId]);
            if ($scenario === 'missing-both') {
                Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [self::LOSER]);
            }
        } elseif (str_starts_with($scenario, 'merged-')) {
            Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, 11, 1)', [$userId]);
        } elseif (str_starts_with($scenario, 'deleting-')) {
            $circle = $scenario === 'deleting-both' ? [self::LOSER, self::SURVIVOR] : [$userId];
            $this->asHolder(fn () => Hilos::$db->accountOperations->actions->recordErasure(
                81, $userId, AccountOperationCircle::fromArray($circle, $userId)));
        } else {
            $other = $this->asHolder(fn () => Hilos::$db->accountOperations->actions->recordMerge(
                $userId, 11, new AccountMergeChoice(null, null, null, null)));
            $this->asHolder(fn () => $this->holder->driveMerge($other));
            foreach ($this->frames() as $ask) {
                if ($ask->data->data->ownerIndex === (string)$userId) {
                    $this->deliver($ask);
                    $this->frames();
                }
            }
        }
        $this->freshContext();
        foreach (array_reverse($asks) as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        $progress = AccountMergeProgressResolver::forOperation($operation->id);
        self::assertSame('refusal', $progress->state);
        self::assertNull($progress->outcome);
        self::assertSame(['code' => $code, 'reason' => $sentence], $progress->refusal);
        self::assertStringNotContainsString('%s', $progress->refusal['reason']);
        self::assertStringNotContainsString('%d', $progress->refusal['reason']);
        self::assertSame($sentence, AccountMergeRefusalText::of($operation, AccountMergeRefusal::from($code)));
    }

    public function testLiftedWaitLeavesItsExpectedProjectPendingWithoutInventingFixedDuties(): void
    {
        $operation = $this->open();
        $participant = 'project:orders:channel';
        $wait = new AccountOperationExpectation($participant, self::LOSER, null);
        $expected = new AccountOperationExpectation($participant, self::LOSER, new AccountOperationOwnerAddress('project:orders', 'east:one'));
        foreach ([$wait, $expected] as $expectation) {
            $this->asHolder(fn () => Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, AccountMergeStage::Preparing->value, $expectation->assignmentKey(),
                $expectation->assignmentOwner(), self::LOSER, $expectation->payload()));
            $progress = AccountMergeProgressResolver::forOperation($operation->id);
            if ($expectation->isWait()) {
                self::assertSame([['participant' => $participant, 'userId' => self::LOSER]], $progress->waits);
                self::assertCount(2, $progress->parts);
            } else {
                self::assertSame([], $progress->waits);
                self::assertSame(['participant' => $participant, 'owner' => 'project:orders', 'userId' => self::LOSER, 'done' => false],
                    $progress->parts[2]);
                self::assertCount(3, $progress->parts);
            }
        }
    }

    public function testEveryRelevantLocalOrAppliedMutationResetsMemoryWhileActivityAndRtDoNot(): void
    {
        $operation = $this->open();
        $previous = AccountMergeProgressResolver::forOperation($operation->id);
        foreach ([TableMutationType::Create, TableMutationType::Update, TableMutationType::Delete, TableMutationType::Clear] as $mutation) {
            foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments,
                HilosDbContext::accountOperationResults, HilosDbContext::userMerges, HilosDbContext::users] as $source) {
                $change = new SourceChange(SourceChange::KIND_DB, $source, '7', $mutation, ['name' => 'changed']);
                SourceChangeBus::whileApplyingRemote(static fn () => SourceChangeBus::publish($change));
                $next = AccountMergeProgressResolver::forOperation($operation->id);
                self::assertNotSame($previous, $next);
                self::assertSame($previous->toArray(), $next->toArray());
                $previous = $next;
            }
        }
        SourceChangeBus::publish(new SourceChange(SourceChange::KIND_RT, HilosDbContext::accountOperations, '7', TableMutationType::Clear));
        SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::users, '7', ['last_activity_at' => 12]));
        SourceChangeBus::publish(SourceChange::dbCleared(HilosDbContext::identities));
        self::assertSame($previous, AccountMergeProgressResolver::forOperation($operation->id));
    }

    /**
     * @param string $userId Person addressed by the registered indexed route
     * @return AbstractUserAgent Registered personal owner with the production handler
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new MergeCoordinatorUser($userId);
    }

    /** Installs the project before opening and seeds actual survivor and loser rows. */
    private function activateProject(): void
    {
        MergeCoordinatorProjectHilos::initBrowser();
        $this->project = new MergeCoordinatorProjectOwner();
        OwnershipDeclaration::claimAll($this->project);
        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (7), (9), (9)');
    }

    /**
     * @param list<string> $keys Assignment asks withheld from the finite real exchange
     * @return list<SignalDTO> Held transport envelopes, with no synthetic result writes
     */
    private function drainExcept(array $keys): array
    {
        $held = [];
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                return $held;
            }
            foreach ($frames as $frame) {
                if ($frame->data->data instanceof AccountOperationAskSignalData && in_array($this->keys([$frame])[0], $keys, true)) {
                    $held[] = $frame;
                } else {
                    $this->deliver($frame);
                }
            }
        }
        self::fail('The held-proof exchange must settle without a retry loop.');
    }

    /**
     * @return array{AccountOperation, SignalDTO, SignalDTO, SignalDTO, SignalDTO} Receipt committed, project and cleanup asks held
     */
    private function projectCleanupWindow(): array
    {
        $this->activateProject();
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        [$initial] = AccountOperationExpectedSet::of($operation->id)->expected();
        $this->deliver($give);
        [$handover, $giveAnswer] = $this->frames();
        $this->deliver($handover);
        [$receipt] = $this->frames();
        $this->deliver($receipt);
        $held = $this->drainExcept([$initial->assignmentKey, AccountMergeDuty::Cleanup->value]);
        self::assertSame([$initial->assignmentKey, 'cleanup'], $this->keys($held));
        self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
        self::assertNull($this->row($operation)['finished_at']);

        return [$operation, $give, $held[0], $held[1], $giveAnswer];
    }

    /** @param AccountOperation $operation Merge whose queued real handlers must settle without manual advance */
    private function finishExchange(AccountOperation $operation): void
    {
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                self::assertSame('completed', $this->row($operation)['outcome']);
                self::assertNotNull($this->row($operation)['finished_at']);
                self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());

                return;
            }
            foreach ($frames as $frame) {
                $this->deliver($frame);
            }
        }
        self::fail('The finite handler exchange must settle without replay loops.');
    }

    /**
     * @param ?AccountMergeChoice $choice Immutable choices
     * @param int $survivor Survivor account
     * @param int $loser Loser account
     * @return AccountOperation Prepared holder opening
     */
    private function open(?AccountMergeChoice $choice = null, int $survivor = self::SURVIVOR, int $loser = self::LOSER): AccountOperation
    {
        return $this->asHolder(fn () => $this->holder->openMerge($survivor, $loser, $choice ?? new AccountMergeChoice(null, null, null, null)));
    }

    /** @return array{AccountOperation, SignalDTO} Real preparation exchange, stopping before the queued give */
    private function readyGo(): array
    {
        $operation = $this->open();
        $initial = AccountOperationExpectedSet::of($operation->id)->expected();
        foreach ($initial as $assignment) {
            self::assertSame('preparing', $assignment->stage);
        }
        foreach ($this->frames() as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        [$give] = $this->frames();
        self::assertSame(['give'], $this->keys([$give]));
        self::assertSame(array_map(static fn (AccountOperationAssignment $assignment): array => $assignment->toArray(), $initial),
            array_map(static fn (AccountOperationAssignment $assignment): array => $assignment->toArray(),
                AccountOperationExpectedSet::of($operation->id)->expected()));

        return [$operation, $give];
    }

    /**
     * @param AccountOperation $operation Cancelling merge
     * @param list<SignalDTO> $releases The two independently addressed release asks
     */
    private function finishCancellation(AccountOperation $operation, array $releases): void
    {
        self::assertSame(['release_loser', 'release_survivor'], $this->keys($releases));
        self::assertSame('cancelling', $this->row($operation)['stage']);
        $this->deliver($releases[0]);
        $this->deliver($this->frames()[0]);
        self::assertNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        $this->deliver($releases[1]);
        $this->deliver($this->frames()[0]);
        self::assertSame('not_started', $this->row($operation)['outcome']);
        self::assertNotNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        self::assertSame([], $this->frames());
    }

    /** @return list<SignalDTO> Real queued envelopes, excluding unrelated sync announcements */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData || $frame->data->data instanceof AccountOperationAnswerSignalData
                    || $frame->data->data instanceof AccountMergeHandoverSignalData)) {
                $frames[] = $frame;
            }
        }

        return $frames;
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
        } elseif ($frame->signalName->getName() === MergeCoordinatorProjectOwner::ACCOUNT_OPERATION_SIGNAL) {
            self::assertNotNull($this->project);
            ExecutionContext::run(new ExecutionFrame(agentId: $this->project->getId()),
                fn () => $this->project->onSignalAgent($data, $sender, $frame->signalName->getName()));
        } else {
            self::assertTrue($data->data instanceof AccountOperationAskSignalData || $data->data instanceof AccountMergeHandoverSignalData);
            $this->asPerson((int)$data->data->ownerIndex, static fn (AbstractUserAgent $agent) =>
                $agent->onSignalAgent($data, $sender, $frame->signalName->getName()));
        }
    }

    /**
     * @param list<SignalDTO> $frames Asks from the queue
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

    /** Mounts the framework AUTH collections without changing the parent configuration. */
    private function freshContext(): void
    {
        Hilos::$db = new MergeCoordinatorContext();
        Hilos::$db->configure();
    }

    /**
     * @param class-string<Throwable> $expected Expected failure category
     * @param callable $work Refused operation
     * @return Throwable Observed failure
     */
    private function refused(string $expected, callable $work): Throwable
    {
        try {
            $work();
        } catch (Throwable $failure) {
            self::assertInstanceOf($expected, $failure);

            return $failure;
        }
        self::fail('The invalid operation must be refused.');
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
