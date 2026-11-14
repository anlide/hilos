<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccessLog\AccessLogEvent;
use Hilos\Auth\AccountOperation\AccountErasureCapture;
use Hilos\Auth\AccountOperation\AccountErasureFinal;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureStart;
use Hilos\Auth\AccountOperation\AccountErasureStartResult;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalRefusedException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalWaitsException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;
use Throwable;

/**
 * The person's agent ends its account as its final in the erasure (HIL-1942).
 *
 * The session holder opens the erasure of a root account's request, the root's agent starts it,
 * every account of the circle captures its addresses and carries its part, the holder records its
 * own part of every account without doing it, and then hands out the finals. The person's agent
 * runs with the rights it receives at the switch - deleting its row, its journal and its merge
 * row - except in the case that proves the agent of today is refused. Every write runs in the frame
 * of the agent whose class declares it, and commits for real.
 */
final class AccountErasureFinalIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Tables the final reaches beyond the base's, raised over it in dependency order. */
    private const array TABLES = [
        'hilos_passkey_credential',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    /** The session holder writes the operations and records its own parts under its own id. */
    private const string HOLDER_ID = HilosAgentType::HILOS_SESSIONS_LIBRARY;

    /** Prefix of the start's assignment key, followed by the account. */
    private const string START_KEY = 'start:';

    /** Payload of the start's assignment, which the start does not read. */
    private const string START_PAYLOAD = '{}';

    /** Payload of the holder's own part, recorded without doing it. */
    private const string HOLDER_PART_PAYLOAD = '{}';

    /** The rows keep epoch milliseconds. */
    private const int CREATED_AT = 1790416800000;

    /** 2036-01-01 00:00:00 UTC: a folded account's own request still stands. */
    private const int FUTURE = 2082758400000;

    private ErasureFinalTestUsersLibrary $users;

    private ErasureFinalTestSessionsHolder $holder;

    private ?SignalRouter $previousRouter = null;

    private string $previousApp;

    /** Whether the person's agent is the framework's own class, without the rights of the switch. */
    private bool $beforeSwitch = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(down: true);
        self::stubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        ErasureFinalTestHilos::initBrowser();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->users = new ErasureFinalTestUsersLibrary();
        $this->holder = new ErasureFinalTestSessionsHolder();
        OwnershipDeclaration::claimDb($this->users::class, $this->users->getId());
        OwnershipDeclaration::claimDb($this->holder::class, self::HOLDER_ID);
        OwnershipDeclaration::claimDbSet($this->holder);
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(down: true);
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            foreach ([$this->users->getId(), self::HOLDER_ID] as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();

            parent::tearDown();
        }
    }

    /**
     * Once every part of the root has its result, its final completes the operation's request and
     * deletes the person's journal, capture and row in one transaction, records which request it
     * completed, and stops the agent. A journal row where the person only renamed someone else stays,
     * without its author.
     */
    public function testTheRootsFinalEndsTheAccountAndStopsTheAgent(): void
    {
        $rootId = $this->seedPerson();
        $otherId = $this->seedPerson();
        self::seedRename($rootId, null);
        $authored = self::seedRename($otherId, $rootId);
        $erasure = $this->prepareFinals($rootId);
        $requestId = $erasure->requestId;
        self::assertNotNull($requestId);
        self::assertSame(1, self::capturesOf($erasure, $rootId));

        $result = $this->runFinal($rootId, self::finalId($erasure, $rootId));

        self::assertSame('{"request":' . $requestId . '}', $result->payload->toJson());
        self::assertFalse(self::personExists($rootId));
        self::assertTrue(self::personExists($otherId));
        self::assertSame([[$authored, $otherId, null]], self::renames());
        self::assertSame(0, self::capturesOf($erasure, $rootId));
        self::assertNotNull(self::completedAt($requestId));
        self::assertTrue(AccountErasureFinal::hasFinal($erasure->id, $rootId));
        self::assertTrue($this->personAgent($rootId)->shouldStop());
    }

    /** A part of the account without its result keeps the final waiting, and nothing is written. */
    public function testAPartWithoutItsResultKeepsTheFinalWaiting(): void
    {
        $rootId = $this->seedPerson();
        self::seedRename($rootId, null);
        $erasure = $this->prepareFinals($rootId, holderParts: false);

        $wait = $this->assertFinalRefused(
            $rootId,
            self::finalId($erasure, $rootId),
            AccountErasureFinalWaitsException::class,
            'The person\'s erasure final waits for the parts of sessions_holder.',
        );

        self::assertInstanceOf(AccountErasureFinalWaitsException::class, $wait);
        self::assertSame([AccountErasureParticipantCatalog::SESSIONS_HOLDER], $wait->participants);
        self::assertSame([], $wait->foldedUserIds);
        self::assertSame([AccountErasureParticipantCatalog::SESSIONS_HOLDER], AccountErasureFinal::missingPartsOf($erasure->id, $rootId));
        self::assertSame(1, self::capturesOf($erasure, $rootId));
        self::assertNull(self::completedAt((int)$erasure->requestId));
        self::assertFalse(AccountErasureFinal::hasFinal($erasure->id, $rootId));
    }

    /** A wait for an owner that no expected owner answers keeps the final waiting as well. */
    public function testAStandingWaitKeepsTheFinalWaiting(): void
    {
        $rootId = $this->seedPerson();
        $erasure = $this->prepareFinals($rootId);
        $wait = new AccountOperationExpectation(AccountErasureParticipantCatalog::USERS_LIBRARY, $rootId, null);
        $this->asHolder(static fn (): AccountOperationAssignment => Hilos::$db->accountOperationAssignments->actions->record(
            $erasure->id,
            $erasure->stage,
            $wait->assignmentKey(),
            $wait->assignmentOwner(),
            $rootId,
            $wait->payload(),
        ));

        $refusal = $this->assertFinalRefused(
            $rootId,
            self::finalId($erasure, $rootId),
            AccountErasureFinalWaitsException::class,
            'The person\'s erasure final waits for the parts of users_library.',
        );

        self::assertInstanceOf(AccountErasureFinalWaitsException::class, $refusal);
        self::assertSame([AccountErasureParticipantCatalog::USERS_LIBRARY], $refusal->participants);
        self::assertNull(self::completedAt((int)$erasure->requestId));
    }

    /**
     * The survivor's final waits for every account still folded into it. A folded account's final
     * deletes its merge row and its row; without a request of its own it names none, and its live
     * request it completes. Then the survivor's final passes.
     */
    public function testTheSurvivorsFinalWaitsForTheFinalsOfTheAccountsFoldedIntoIt(): void
    {
        $rootId = $this->seedPerson();
        $quietId = $this->seedPerson();
        $requestingId = $this->seedPerson();
        $erasure = $this->prepareFinals($rootId, [$quietId, $requestingId]);
        $requestId = self::seedRequest($requestingId);

        $wait = $this->assertFinalRefused(
            $rootId,
            self::finalId($erasure, $rootId),
            AccountErasureFinalWaitsException::class,
            "The person's erasure final waits for the accounts folded into it: #{$quietId}, #{$requestingId}.",
        );
        self::assertInstanceOf(AccountErasureFinalWaitsException::class, $wait);
        self::assertSame([], $wait->participants);
        self::assertSame([$quietId, $requestingId], $wait->foldedUserIds);

        $quiet = $this->runFinal($quietId, self::finalId($erasure, $quietId));
        self::assertSame('{"request":null}', $quiet->payload->toJson());
        self::assertFalse(self::personExists($quietId));
        self::assertSame([[$requestingId, $rootId]], self::merges());
        self::assertTrue($this->personAgent($quietId)->shouldStop());

        $wait = $this->assertFinalRefused(
            $rootId,
            self::finalId($erasure, $rootId),
            AccountErasureFinalWaitsException::class,
            "The person's erasure final waits for the accounts folded into it: #{$requestingId}.",
        );
        self::assertInstanceOf(AccountErasureFinalWaitsException::class, $wait);
        self::assertSame([$requestingId], $wait->foldedUserIds);

        $requesting = $this->runFinal($requestingId, self::finalId($erasure, $requestingId));
        self::assertSame('{"request":' . $requestId . '}', $requesting->payload->toJson());
        self::assertNotNull(self::completedAt($requestId));
        self::assertFalse(self::personExists($requestingId));
        self::assertSame([], self::merges());

        $root = $this->runFinal($rootId, self::finalId($erasure, $rootId));
        self::assertSame('{"request":' . $erasure->requestId . '}', $root->payload->toJson());
        self::assertFalse(self::personExists($rootId));
    }

    /**
     * A row the holder's part left behind makes the database refuse the delete of the person's row:
     * the whole final rolls back, the refusal names the table and not the engine's words, and the
     * agent goes on. The holder keeps that reason on the operation; once the row is gone, the same
     * final passes and the reason no longer stands.
     */
    public function testARestrictedDeleteRollsTheFinalBackAndItsReasonStaysUntilARepeatPasses(): void
    {
        $rootId = $this->seedPerson();
        $foldedId = $this->seedPerson();
        $outsiderId = $this->seedPerson();
        self::seedRename($foldedId, null);
        $erasure = $this->prepareFinals($rootId, [$foldedId]);
        $requestId = self::seedRequest($foldedId);
        Database::sqlRun(
            'INSERT INTO `hilos_access_log` (`user_id`, `event`, `ip_address`, `occurred_at`) VALUES (?, ?, NULL, ?)',
            [$foldedId, AccessLogEvent::SIGN_IN->value, self::CREATED_AT],
        );
        $finalId = self::finalId($erasure, $foldedId);

        $refusal = $this->assertFinalRefused(
            $foldedId,
            $finalId,
            AccountErasureFinalRefusedException::class,
            'The person\'s erasure final was refused: table hilos_access_log still references the account.',
        );

        self::assertInstanceOf(AccountErasureFinalRefusedException::class, $refusal);
        self::assertSame($foldedId, $refusal->userId);
        self::assertSame('hilos_access_log', $refusal->table);
        $engine = $refusal->getPrevious();
        self::assertInstanceOf(ForeignKeyConstraintException::class, $engine);
        self::assertSame(ForeignKeyConstraintException::ROW_IS_REFERENCED_ERROR_CODE, $engine->getMysqlErrorCode());
        self::assertStringNotContainsString($engine->getMysqlErrorMessage(), $refusal->getMessage());
        self::assertStringNotContainsString('foreign key', $refusal->getMessage());
        self::assertSame(1, self::capturesOf($erasure, $foldedId));
        self::assertNull(self::completedAt($requestId));

        $catalog = AccountErasureParticipantCatalog::framework();
        self::assertSame([], $this->asHolder(static fn (): array => AccountErasureFinal::refused($erasure, $catalog, $refusal)));
        self::assertSame([$foldedId => 'hilos_access_log'], self::finalRefusals($erasure));
        $standing = Hilos::$db->accountOperations[$erasure->id];
        self::assertSame(
            [[AccountErasureFinal::userId => $foldedId, AccountErasureFinal::table => 'hilos_access_log']],
            AccountErasureFinal::refusalsOf($standing),
        );
        $stranger = AccountErasureFinalRefusedException::of($outsiderId, null, $engine);
        try {
            $this->asHolder(static fn (): array => AccountErasureFinal::refused($erasure, $catalog, $stranger));
            self::fail('A refusal of an account outside the circle was kept');
        } catch (ValidationException $e) {
            self::assertSame('A refused final must name an account of its erasure.', $e->getMessage());
        }

        Database::sqlRun('DELETE FROM `hilos_access_log` WHERE `user_id` = ?', [$foldedId]);
        $passed = $this->runFinal($foldedId, $finalId);

        self::assertSame('{"request":' . $requestId . '}', $passed->payload->toJson());
        self::assertFalse(self::personExists($foldedId));
        self::assertSame([], self::renames());
        self::assertSame([], AccountErasureFinal::refusalsOf(Hilos::$db->accountOperations[$erasure->id]));
    }

    /** A repeat of a final that passed answers the saved result without the work and stops the agent again. */
    public function testARepeatAnswersTheSavedFinalAndStopsTheAgentAgain(): void
    {
        $rootId = $this->seedPerson();
        $erasure = $this->prepareFinals($rootId);
        $finalId = self::finalId($erasure, $rootId);
        $first = $this->runFinal($rootId, $finalId);
        $state = self::state();
        $this->releasePersonAgents();
        self::assertFalse($this->personAgent($rootId)->shouldStop());

        $repeat = $this->runFinal($rootId, $finalId);

        self::assertSame($first->id, $repeat->id);
        self::assertSame($first->payload->toJson(), $repeat->payload->toJson());
        self::assertSame($state, self::state());
        self::assertTrue($this->personAgent($rootId)->shouldStop());
    }

    /** The agent of today, without the rights of the switch, is refused by the guard and writes nothing. */
    public function testTheAgentWithoutTheRightsOfTheSwitchIsRefused(): void
    {
        $this->beforeSwitch = true;
        $rootId = $this->seedPerson();
        self::seedRename($rootId, null);
        $erasure = $this->prepareFinals($rootId);

        $this->assertFinalRefused($rootId, self::finalId($erasure, $rootId), WriteNotAllowedException::class, null);

        self::assertInstanceOf(IntegrationUserAgent::class, $this->personAgent($rootId));
        self::assertSame(1, self::capturesOf($erasure, $rootId));
        self::assertNull(self::completedAt((int)$erasure->requestId));
        self::assertFalse(AccountErasureFinal::hasFinal($erasure->id, $rootId));
    }

    /**
     * Every account of an open erasure gets one final, at the current stage and written to its own
     * agent; a repeat writes nothing, and a closed erasure records none.
     */
    public function testTheFinalsAreHandedOutOncePerAccountOfAnOpenErasure(): void
    {
        $rootId = $this->seedPerson();
        $foldedId = $this->seedPerson();
        self::seedMerge($foldedId, $rootId);
        $erasure = $this->openErasure($rootId);

        $recorded = $this->asHolder(static fn (): array => AccountErasureFinal::recordMissing($erasure));

        self::assertSame([$foldedId, $rootId], $erasure->circle->toArray());
        self::assertSame(
            [
                [AccountErasureFinal::personalKey($foldedId), $erasure->stage, "hilos_user:{$foldedId}", $foldedId, '{"participant":"personal"}'],
                [AccountErasureFinal::personalKey($rootId), $erasure->stage, "hilos_user:{$rootId}", $rootId, '{"participant":"personal"}'],
            ],
            array_map(
                static fn (AccountOperationAssignment $assignment): array => [
                    $assignment->assignmentKey,
                    $assignment->stage,
                    $assignment->owner->agentId(),
                    $assignment->userId,
                    $assignment->payload->toJson(),
                ],
                $recorded,
            ),
        );
        self::assertSame("final:personal:hilos_user:{$rootId}:{$rootId}", AccountErasureFinal::personalKey($rootId));
        self::assertSame([], $this->asHolder(static fn (): array => AccountErasureFinal::recordMissing($erasure)));

        $this->asHolder(static fn (): bool => Database::transaction(
            DatabaseNames::PRIMARY,
            static fn (): bool => $erasure->actions->finish($erasure->stage, AccountOperationOutcome::NotStarted),
        ));
        $closed = Hilos::$db->accountOperations[$erasure->id];
        try {
            $this->asHolder(static fn (): array => AccountErasureFinal::recordMissing($closed));
            self::fail('A closed erasure recorded its finals');
        } catch (ValidationException $e) {
            self::assertSame('Only an open erasure records its finals.', $e->getMessage());
        }
    }

    /** The erasure ends only once every account of its circle has its final, and writes nothing before. */
    public function testTheErasureEndsOnlyAfterTheFinalOfEveryAccount(): void
    {
        $rootId = $this->seedPerson();
        $foldedId = $this->seedPerson();
        $erasure = $this->prepareFinals($rootId, [$foldedId]);

        self::assertFalse($this->asHolder(static fn (): bool => AccountErasureFinal::end($erasure)));
        self::assertSame([null, null], self::ending($erasure));
        $this->runFinal($foldedId, self::finalId($erasure, $foldedId));
        self::assertFalse($this->asHolder(static fn (): bool => AccountErasureFinal::end($erasure)));
        self::assertSame([null, null], self::ending($erasure));
        $this->runFinal($rootId, self::finalId($erasure, $rootId));

        self::assertTrue($this->asHolder(static fn (): bool => AccountErasureFinal::end($erasure)));
        [$outcome, $finishedAt] = self::ending($erasure);
        self::assertSame(AccountOperationOutcome::Completed->value, $outcome);
        self::assertNotNull($finishedAt);
        $ended = Hilos::$db->accountOperations[$erasure->id];
        foreach ([
            'Only an open erasure ends.' => static fn (): bool => AccountErasureFinal::end($ended),
            'Only an open erasure records a refused final.' => static fn (): array => AccountErasureFinal::refused(
                $ended,
                AccountErasureParticipantCatalog::framework(),
                AccountErasureFinalRefusedException::of($rootId, null, new ForeignKeyConstraintException('refused')),
            ),
        ] as $words => $step) {
            try {
                $this->asHolder($step);
                self::fail("A closed erasure was not refused: {$words}");
            } catch (ValidationException $e) {
                self::assertSame($words, $e->getMessage());
            }
        }
    }

    /** An assignment of another operation's kind is refused before any work. */
    public function testAFinalOutsideAnErasureIsRefused(): void
    {
        $rootId = $this->seedPerson();
        $otherId = $this->seedPerson();
        $merge = $this->asHolder(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordMerge(
            $otherId,
            $rootId,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        ));

        $this->assertFinalRefused(
            $rootId,
            $this->assign($merge, AccountErasureFinal::personalKey($rootId), $rootId, $rootId, self::finalPayload($rootId)),
            ValidationException::class,
            'An account erasure final must belong to an erasure.',
        );
    }

    /** The person's own part handed to the final is refused before any work. */
    public function testThePersonsPartIsNotItsFinal(): void
    {
        $rootId = $this->seedPerson();
        $erasure = $this->openErasure($rootId);
        $this->startRoot($erasure, $rootId);

        $this->assertFinalRefused(
            $rootId,
            self::personalPart($erasure, $rootId),
            ValidationException::class,
            'This assignment is not the person\'s erasure final.',
        );
    }

    /** A final naming another account than the agent's own is refused before any work. */
    public function testAFinalOfAnotherAccountIsRefused(): void
    {
        $rootId = $this->seedPerson();
        $foldedId = $this->seedPerson();
        self::seedMerge($foldedId, $rootId);
        $erasure = $this->openErasure($rootId);
        $this->startRoot($erasure, $rootId);

        $this->assertFinalRefused(
            $rootId,
            $this->assign($erasure, AccountErasureFinal::personalKey($rootId), $rootId, $foldedId, self::finalPayload($foldedId)),
            ValidationException::class,
            'An account erasure final must name its own account.',
        );
    }

    /**
     * The person's agent with the rights of the switch, or the framework's own class when the case
     * proves the agent of today is refused.
     *
     * @param string $userId Person id as the agent address carries it
     * @return AbstractUserAgent The agent, not yet claimed
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return $this->beforeSwitch ? new IntegrationUserAgent($userId) : new ErasureFinalTestUserAgent($userId);
    }

    /**
     * Opens the erasure of the root's request with the given accounts folded into the root, starts
     * it, has every account capture and carry its part, records the holder's own parts unless the
     * case keeps them back, and hands out the finals.
     *
     * @param int $rootId Account whose deletion is requested
     * @param list<int> $foldedIds Accounts folded into the root
     * @param bool $holderParts Whether the holder records its own part of every account
     * @return AccountOperation The open erasure with its finals handed out
     */
    private function prepareFinals(int $rootId, array $foldedIds = [], bool $holderParts = true): AccountOperation
    {
        foreach ($foldedIds as $foldedId) {
            self::seedMerge($foldedId, $rootId);
        }
        $erasure = $this->openErasure($rootId);
        $this->startRoot($erasure, $rootId);
        $this->asHolder(
            static fn (): array => AccountErasureCapture::recordAfterStart($erasure, AccountErasureParticipantCatalog::framework()),
        );
        foreach ($erasure->circle->toArray() as $userId) {
            $captureId = self::assignmentId($erasure, AccountErasureCapture::personalKey($userId));
            $this->asPerson($userId, static fn (AbstractUserAgent $agent): AccountOperationResult => $agent->captureForErasure($captureId));
            $partId = self::personalPart($erasure, $userId);
            $this->asPerson($userId, static fn (AbstractUserAgent $agent): AccountOperationResult => $agent->eraseAccountPersonalPart($partId));
        }
        if ($holderParts) {
            $this->recordHolderParts($erasure);
        }
        $this->asHolder(static fn (): array => AccountErasureFinal::recordMissing($erasure));

        return $erasure;
    }

    /**
     * Records the holder's own part of every account in its own frame, without doing it.
     *
     * @param AccountOperation $erasure Open erasure
     */
    private function recordHolderParts(AccountOperation $erasure): void
    {
        $this->asHolder(static function () use ($erasure): void {
            foreach (Hilos::$db->accountOperationAssignments->forOperation($erasure->id) as $assignment) {
                if (AccountOperationExpectation::isExpectKey($assignment->assignmentKey) && $assignment->owner->agentId() === self::HOLDER_ID) {
                    Database::transaction(
                        DatabaseNames::PRIMARY,
                        static fn (): AccountOperationResult => Hilos::$db->accountOperationResults->actions->record(
                            $assignment,
                            AccountOperationResultPayload::fromJson(self::HOLDER_PART_PAYLOAD),
                        ),
                    );
                }
            }
        });
    }

    /**
     * Opens a request a day ahead in the users library's frame, then the erasure that answers it in
     * the session holder's frame.
     *
     * @param int $rootId Account whose deletion is requested
     * @return AccountOperation The open erasure, at the stage the opening left it
     */
    private function openErasure(int $rootId): AccountOperation
    {
        $at = TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->users->getId()),
            static fn () => Hilos::$db->accountDeletions->actions->request($rootId, $at),
        );
        $request = Hilos::$db->accountDeletions->liveOf($rootId);
        self::assertNotNull($request);

        return $this->asHolder(static function () use ($request): AccountOperation {
            $erasure = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->operation;
            self::assertNotNull($erasure);

            return $erasure;
        });
    }

    /**
     * Closes the root account: the root's agent starts the erasure on its request.
     *
     * @param AccountOperation $erasure Open erasure of the root's request
     * @param int $rootId Root account
     */
    private function startRoot(AccountOperation $erasure, int $rootId): void
    {
        $assignmentId = $this->assign(
            $erasure,
            self::START_KEY . $rootId,
            $rootId,
            $rootId,
            AccountOperationAssignmentPayload::fromJson(self::START_PAYLOAD),
        );
        $result = $this->asPerson($rootId, static fn (): AccountOperationResult => AccountErasureStart::run($assignmentId));
        self::assertTrue(AccountErasureStartResult::startedOf($result->payload));
    }

    /**
     * Records one assignment at the operation's current stage, written to a person's agent, in the
     * session holder's frame.
     *
     * @param AccountOperation $operation Open operation
     * @param string $key Assignment key
     * @param int $ownerId Person whose agent the assignment is written to
     * @param int $userId Account the assignment is about
     * @param AccountOperationAssignmentPayload $payload Assignment payload
     * @return int Id of the assignment
     */
    private function assign(
        AccountOperation $operation,
        string $key,
        int $ownerId,
        int $userId,
        AccountOperationAssignmentPayload $payload,
    ): int {
        return $this->asHolder(static fn (): int => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            $key,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$ownerId),
            $userId,
            $payload,
        )->id);
    }

    /**
     * Runs one step in the session holder's own frame, the way its worker would.
     *
     * @template T
     * @param callable(): T $step Step to run as the holder
     * @return T Whatever the step returns
     */
    private function asHolder(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), $step);
    }

    /**
     * @param int $userId Account whose agent runs its final
     * @param int $assignmentId The final's assignment
     * @return AccountOperationResult The recorded or saved final
     */
    private function runFinal(int $userId, int $assignmentId): AccountOperationResult
    {
        return $this->asPerson(
            $userId,
            static fn (AbstractUserAgent $agent): AccountOperationResult => $agent->finishAccountErasure($assignmentId),
        );
    }

    /**
     * @param int $userId Account whose agent is given the assignment
     * @param int $assignmentId Assignment the agent is given
     * @param class-string<Throwable> $class Kind of the refusal
     * @param ?string $words Words of the refusal, or null when the guard's own words are not the case's subject
     * @return Throwable The refusal
     */
    private function assertFinalRefused(int $userId, int $assignmentId, string $class, ?string $words): Throwable
    {
        $state = self::state();
        $stopped = $this->personAgent($userId)->shouldStop();
        try {
            $this->runFinal($userId, $assignmentId);
        } catch (Throwable $refusal) {
            self::assertInstanceOf($class, $refusal);
            if ($words !== null) {
                self::assertSame($words, $refusal->getMessage());
            }
            self::assertFalse(Database::hasOpenTransactions());
            self::assertSame($state, self::state());
            self::assertTrue(self::personExists($userId));
            self::assertSame($stopped, $this->personAgent($userId)->shouldStop());

            return $refusal;
        }
        self::fail('The final was not refused');
    }

    /**
     * @param AccountOperation $erasure Open erasure
     * @param int $userId Account of its circle
     * @return int Id of the person's part the census expects of that account's agent
     */
    private static function personalPart(AccountOperation $erasure, int $userId): int
    {
        $parts = [];
        foreach (Hilos::$db->accountOperationAssignments->forOperation($erasure->id) as $assignment) {
            if (
                AccountOperationExpectation::isExpectKey($assignment->assignmentKey)
                && AccountOperationExpectation::participantOf($assignment->payload) === AccountErasureParticipantCatalog::PERSONAL
                && $assignment->userId === $userId
            ) {
                $parts[] = $assignment->id;
            }
        }
        self::assertCount(1, $parts);

        return $parts[0];
    }

    /**
     * @param AccountOperation $erasure Erasure whose finals were handed out
     * @param int $userId Account of its circle
     * @return int Id of that account's final
     */
    private static function finalId(AccountOperation $erasure, int $userId): int
    {
        return self::assignmentId($erasure, AccountErasureFinal::personalKey($userId));
    }

    /**
     * @param AccountOperation $operation Operation whose assignments are read
     * @param string $key Assignment key
     * @return int Id of the assignment stored under that key
     */
    private static function assignmentId(AccountOperation $operation, string $key): int
    {
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            if ($assignment->assignmentKey === $key) {
                return $assignment->id;
            }
        }
        self::fail("No assignment {$key}");
    }

    /**
     * @param int $userId Account the final is about
     * @return AccountOperationAssignmentPayload The payload of a final, which names the person's participant
     */
    private static function finalPayload(int $userId): AccountOperationAssignmentPayload
    {
        return new AccountOperationExpectation(
            AccountErasureParticipantCatalog::PERSONAL,
            $userId,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
        )->payload();
    }

    /**
     * @return int Id of a new person row
     */
    private function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Person', 0)");

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Renamed person
     * @param ?int $renamedBy Person who renamed them, or null for their own rename
     * @return int Id of the journal row
     */
    private static function seedRename(int $userId, ?int $renamedBy): int
    {
        Database::sql(
            'INSERT INTO `hilos_user_rename` (`user_id`, `renamed_by_user_id`, `old_name`, `new_name`, `renamed_at`) VALUES (?, ?, ?, ?, ?)',
            [$userId, $renamedBy, 'Person', 'Renamed person', self::CREATED_AT],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Account folded into another one
     * @param int $survivorId Account it was folded into
     */
    private static function seedMerge(int $userId, int $survivorId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$userId, $survivorId, TimeHelper::nowMs()],
        );
    }

    /**
     * @param int $userId Folded account with a live deletion request of its own
     * @return int Id of the request
     */
    private static function seedRequest(int $userId): int
    {
        Database::sql(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`) VALUES (?, ?, ?)',
            [$userId, self::CREATED_AT, self::FUTURE],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Person
     * @return bool Whether the person's own row is stored
     */
    private static function personExists(int $userId): bool
    {
        return Database::sql('SELECT `id` FROM `hilos_user` WHERE `id` = ?', [$userId])->rows() !== [];
    }

    /** @return list<array{int, int, ?int}> Id, person and author of every journal row, in id order */
    private static function renames(): array
    {
        return array_map(
            static fn (array $row): array => [
                (int)$row['id'],
                (int)$row['user_id'],
                $row['renamed_by_user_id'] === null ? null : (int)$row['renamed_by_user_id'],
            ],
            Database::sql('SELECT `id`, `user_id`, `renamed_by_user_id` FROM `hilos_user_rename` ORDER BY `id`')->rows(),
        );
    }

    /** @return list<array{int, ?int}> Folded account and survivor of every merge row, by folded account */
    private static function merges(): array
    {
        return array_map(
            static fn (array $row): array => [
                (int)$row['user_id'],
                $row['survivor_user_id'] === null ? null : (int)$row['survivor_user_id'],
            ],
            Database::sql('SELECT `user_id`, `survivor_user_id` FROM `hilos_user_merge` ORDER BY `user_id`')->rows(),
        );
    }

    /**
     * @param int $requestId Deletion request
     * @return ?int When it was carried out, or null while it is not
     */
    private static function completedAt(int $requestId): ?int
    {
        $completedAt = Database::sql('SELECT `completed_at` FROM `hilos_account_deletion` WHERE `id` = ?', [$requestId])->row()['completed_at'];

        return $completedAt === null ? null : (int)$completedAt;
    }

    /**
     * @param AccountOperation $erasure Erasure whose capture is counted
     * @param int $userId Account of its circle
     * @return int Saved results of that account's capture
     */
    private static function capturesOf(AccountOperation $erasure, int $userId): int
    {
        return (int)Database::sql(
            'SELECT COUNT(*) AS `count` FROM `hilos_account_operation_result` `r`'
                . ' JOIN `hilos_account_operation_assignment` `a` ON `a`.`id` = `r`.`assignment_id`'
                . ' WHERE `a`.`operation_id` = ? AND `a`.`assignment_key` = ?',
            [$erasure->id, AccountErasureCapture::personalKey($userId)],
        )->row()['count'];
    }

    /**
     * @param AccountOperation $erasure Erasure whose refusals are read
     * @return array<int, ?string> The stored refusals by account, as the column keeps them
     */
    private static function finalRefusals(AccountOperation $erasure): array
    {
        $stored = Database::sql('SELECT `final_refusals` FROM `hilos_account_operation` WHERE `id` = ?', [$erasure->id])->row()['final_refusals'];
        self::assertIsString($stored);

        return json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param AccountOperation $erasure Erasure whose end is read
     * @return array{?string, ?int} Stored outcome and moment it finished, both null while it is open
     */
    private static function ending(AccountOperation $erasure): array
    {
        $row = Database::sql('SELECT `outcome`, `finished_at` FROM `hilos_account_operation` WHERE `id` = ?', [$erasure->id])->row();

        return [$row['outcome'], $row['finished_at'] === null ? null : (int)$row['finished_at']];
    }

    /**
     * @return list<list<array<string, mixed>>> People, journal, merges, requests, results and operations, each in key order
     */
    private static function state(): array
    {
        return array_map(
            static fn (string $query): array => Database::sql($query)->rows(),
            [
                'SELECT `id` FROM `hilos_user` ORDER BY `id`',
                'SELECT `id`, `user_id`, `renamed_by_user_id` FROM `hilos_user_rename` ORDER BY `id`',
                'SELECT `user_id`, `survivor_user_id` FROM `hilos_user_merge` ORDER BY `user_id`',
                'SELECT `id`, `started_at`, `canceled_at`, `completed_at` FROM `hilos_account_deletion` ORDER BY `id`',
                'SELECT `id`, `assignment_id`, `owner_agent_id`, `payload_json` FROM `hilos_account_operation_result` ORDER BY `id`',
                'SELECT `id`, `stage`, `finished_at`, `outcome`, `final_refusals` FROM `hilos_account_operation` ORDER BY `id`',
            ],
        );
    }

    /**
     * @param bool $down Drop in reverse dependency order
     */
    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** The framework's users library under a test name, with nothing overridden. */
final class ErasureFinalTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_erasure_final_users_library';
}

/** The framework's session holder, writing the account operations of a case and recording its own parts. */
final class ErasureFinalTestSessionsHolder extends AbstractSessionsLibraryAgent
{
}

/**
 * The person's agent with the rights it receives at the switch (HIL-1952): removing its own row,
 * its rename journal and its merge row.
 */
final class ErasureFinalTestUserAgent extends AbstractUserAgent
{
    /** @var array<string, list<TruthSourceOperation>> The person's row, now with its removal */
    public const array OWNS_DB_ROWS = [
        HilosDbContext::users => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
    ];

    /** @var array<string, list<TruthSourceOperation>> The framework agent's sets, the journal and the merge row now removed here too */
    public const array OWNS_DB_SET = [
        ...AbstractUserAgent::OWNS_DB_SET,
        HilosDbContext::userRenames => [TruthSourceOperation::Add, TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::userMerges => [TruthSourceOperation::Remove],
    ];
}

/** The installation of the case: the person's agent per person and the session holder, so the census expects both parts. */
abstract class ErasureFinalTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => ErasureFinalTestUserAgent::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => ErasureFinalTestSessionsHolder::class],
    ];
}
