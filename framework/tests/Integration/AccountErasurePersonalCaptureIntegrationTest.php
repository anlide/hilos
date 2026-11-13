<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountErasureCapture;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasurePersonalCaptureResult;
use Hilos\Auth\AccountOperation\AccountErasureStart;
use Hilos\Auth\AccountOperation\AccountErasureStartResult;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The capture of the person's agent in the erasure of an account: its addresses and the project's
 * part, written down after the account closed and before its ways in go (HIL-1940).
 *
 * The session holder opens the erasure of a root account's request, the root's agent starts it,
 * and the holder hands the capture to the agent of one account of the circle. Every write runs in
 * the frame of the agent whose class declares it, and commits for real.
 */
final class AccountErasurePersonalCaptureIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Tables of the account operations, raised over the base's. */
    private const array TABLES = [
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    /** Agent id the session holder writes the operations under. */
    private const string HOLDER_ID = 'erasure-capture-holder';

    /** Stage the case gives the start at: the start's own stage is not this leaf's. */
    private const string START_STAGE = 'starting';

    /** Stage the case gives the capture at: the capture's own stage is HIL-1950's. */
    private const string CAPTURE_STAGE = 'capturing';

    /** Stage the case moves the erasure on to after the capture: the stages after it are HIL-1950's too. */
    private const string LATER_STAGE = 'deleting';

    /** Prefix of the start's assignment key, followed by the account. */
    private const string START_KEY = 'start:';

    /** Payload of the start's assignment, which the start does not read. */
    private const string START_PAYLOAD = '{}';

    /** Payload of the personal capture's assignment. */
    private const string CAPTURE_PAYLOAD = '{"participant":"' . AccountErasureParticipantCatalog::PERSONAL . '"}';

    private ErasureCaptureTestUsersLibrary $users;

    private ?SignalRouter $previousRouter = null;

    /** @var Closure(int): array<string, mixed> The project's part every person's agent of the case answers with */
    private Closure $projectPart;

    /** How many times a person's agent asked the project for its part. */
    private int $projectPartCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        self::stubs(down: true);
        self::stubs(down: false);
        $this->projectPart = static fn (int $userId): array => [];
        $this->users = new ErasureCaptureTestUsersLibrary();
        OwnershipDeclaration::claimDb($this->users::class, $this->users->getId());
        OwnershipDeclaration::claimDb(ErasureCaptureTestSessionsHolder::class, self::HOLDER_ID);
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(down: true);
        } finally {
            $this->releasePersonAgents();
            foreach ([$this->users->getId(), self::HOLDER_ID] as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();

            parent::tearDown();
        }
    }

    /**
     * After the start the capture records every address of the person once, in ascending order, and
     * an empty project object; another person's address is not among them.
     */
    public function testAfterTheStartTheCaptureRecordsThePersonsAddressesOnly(): void
    {
        $userId = $this->seedPerson();
        self::seedAddresses($userId);
        $otherId = $this->seedPerson();
        self::seedIdentity($otherId, IdentityType::PASSWORD, 'other@example.test');
        $erasure = $this->openErasure($userId);
        $this->startRoot($erasure, $userId);
        $assignmentId = $this->assignCapture($erasure, $userId);

        $result = $this->runCapture($userId, $assignmentId);

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame("capture:personal:hilos_user:{$userId}:{$userId}", AccountErasureCapture::personalKey($userId));
        self::assertSame(
            '{"addresses":["+15550100","adam@example.test","zoe@example.test"],"project":{}}',
            $result->payload->toJson(),
        );
        self::assertSame(
            [[$assignmentId, HilosAgentType::HILOS_USER . ':' . $userId, $result->payload->toJson()]],
            self::storedCaptures(),
        );
    }

    /** Before the start the account has not closed: the capture is refused and nothing is recorded. */
    public function testBeforeTheStartTheCaptureWaitsForTheAccountToClose(): void
    {
        $userId = $this->seedPerson();
        self::seedAddresses($userId);
        $erasure = $this->openErasure($userId);
        $assignmentId = $this->assignCapture($erasure, $userId);

        $this->assertCaptureRefused($userId, $assignmentId, 'An account erasure capture waits for the account to close.');
        self::assertSame(0, $this->projectPartCalls);
    }

    /** An account folded into the root has no request of its own: its merge row closed it, and it captures its own addresses. */
    public function testAFoldedAccountWithoutItsOwnRequestCapturesItsAddresses(): void
    {
        $rootId = $this->seedPerson();
        self::seedIdentity($rootId, IdentityType::PASSWORD, 'root@example.test');
        $foldedId = $this->seedPerson();
        self::seedIdentity($foldedId, IdentityType::PASSWORD, 'folded@example.test');
        self::seedMerge($foldedId, $rootId);
        $erasure = $this->openErasure($rootId);
        $this->startRoot($erasure, $rootId);
        $assignmentId = $this->assignCapture($erasure, $foldedId);
        self::assertNull(Hilos::$db->accountDeletions->startedOf($foldedId));

        $result = $this->runCapture($foldedId, $assignmentId);

        self::assertSame('{"addresses":["folded@example.test"],"project":{}}', $result->payload->toJson());
    }

    /**
     * A stop between the capture and the deletion of the ways in: a new agent on the same assignment
     * answers the saved capture with the former addresses, and asks for nothing again.
     */
    public function testANewAgentAfterTheWaysInAreGoneAnswersTheSavedCapture(): void
    {
        $userId = $this->seedPerson();
        self::seedAddresses($userId);
        $erasure = $this->openErasure($userId);
        $this->startRoot($erasure, $userId);
        $assignmentId = $this->assignCapture($erasure, $userId);
        $first = $this->runCapture($userId, $assignmentId);
        self::assertSame(1, $this->projectPartCalls);
        $this->asPerson($userId, static fn () => Hilos::$db->identities->deleteForUser($userId));
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_identity` WHERE `user_id` = ?', [$userId])->rows());
        $stored = self::storedCaptures();
        $this->releasePersonAgents();

        $repeat = $this->runCapture($userId, $assignmentId);

        self::assertSame($first->id, $repeat->id);
        self::assertSame(
            ['+15550100', 'adam@example.test', 'zoe@example.test'],
            AccountErasurePersonalCaptureResult::fromPayload($repeat->payload)->addresses,
        );
        self::assertSame(1, $this->projectPartCalls);
        self::assertSame($stored, self::storedCaptures());
    }

    /**
     * An assignment that is not this person's capture - the person's expected-owner assignment, or
     * the capture of another account of the circle handed to this agent - is refused, and nothing
     * is recorded.
     */
    public function testAnAssignmentThatIsNotThePersonsOwnCaptureIsRefused(): void
    {
        $userId = $this->seedPerson();
        self::seedAddresses($userId);
        $foldedId = $this->seedPerson();
        self::seedIdentity($foldedId, IdentityType::PASSWORD, 'folded@example.test');
        self::seedMerge($foldedId, $userId);
        $erasure = $this->openErasure($userId);
        $expectation = new AccountOperationExpectation(
            AccountErasureParticipantCatalog::PERSONAL,
            $userId,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
        );
        $expectedId = $this->asHolder(static fn (): int => self::assign(
            $erasure,
            $erasure->stage,
            $expectation->assignmentKey(),
            $userId,
            $userId,
            $expectation->payload()->toJson(),
        ));

        $this->assertCaptureRefused($userId, $expectedId, 'An account erasure capture must answer its own capture assignment.');

        $this->startRoot($erasure, $userId);
        $foreignId = $this->asHolder(static fn (): int => self::assign(
            $erasure,
            self::CAPTURE_STAGE,
            AccountErasureCapture::personalKey($foldedId),
            $userId,
            $foldedId,
            self::CAPTURE_PAYLOAD,
        ));

        $this->assertCaptureRefused($userId, $foreignId, 'An account erasure capture must answer its own capture assignment.');
        self::assertSame(0, $this->projectPartCalls);
    }

    /** A capture handed out inside a merge is refused, and nothing is recorded. */
    public function testACaptureAssignedInAMergeIsRefused(): void
    {
        $survivorId = $this->seedPerson();
        $loserId = $this->seedPerson();
        self::seedIdentity($survivorId, IdentityType::PASSWORD, 'survivor@example.test');
        $assignmentId = $this->asHolder(static fn (): int => self::assign(
            Hilos::$db->accountOperations->actions->recordMerge(
                $survivorId,
                $loserId,
                new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
            ),
            self::CAPTURE_STAGE,
            AccountErasureCapture::personalKey($survivorId),
            $survivorId,
            $survivorId,
            self::CAPTURE_PAYLOAD,
        ));

        $this->assertCaptureRefused($survivorId, $assignmentId, 'An account erasure capture must belong to an erasure.');
    }

    /**
     * A throw of the project's part rolls the whole capture back - what the part wrote and the result
     * alike - and a later capture with a healthy part records.
     */
    public function testAFailingProjectPartLeavesNeitherAResultNorItsWrite(): void
    {
        $userId = $this->seedPerson();
        self::seedAddresses($userId);
        $erasure = $this->openErasure($userId);
        $this->startRoot($erasure, $userId);
        $assignmentId = $this->assignCapture($erasure, $userId);
        $failure = new LogicException('The project part failed.');
        $this->projectPart = static function (int $userId) use ($failure): array {
            Database::sqlRun("UPDATE `hilos_user` SET `name` = 'Written by the part' WHERE `id` = ?", [$userId]);

            throw $failure;
        };

        try {
            $this->runCapture($userId, $assignmentId);
            self::fail('The capture did not fail');
        } catch (LogicException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame('Person', Database::sql('SELECT `name` FROM `hilos_user` WHERE `id` = ?', [$userId])->row()['name']);
        self::assertSame([], self::storedCaptures());

        $this->projectPart = static fn (int $userId): array => ['renameEvents' => [4, 9]];
        $result = $this->runCapture($userId, $assignmentId);

        self::assertSame(
            '{"addresses":["+15550100","adam@example.test","zoe@example.test"],"project":{"renameEvents":[4,9]}}',
            $result->payload->toJson(),
        );
    }

    /**
     * The capture of an account is read back from its erasure: none before it is recorded, the
     * addresses after, and still the addresses once the erasure has moved on to a later stage.
     */
    public function testOfReadsNoCaptureBeforeItIsRecordedAndTheAddressesAfter(): void
    {
        $userId = $this->seedPerson();
        self::seedAddresses($userId);
        $erasure = $this->openErasure($userId);
        $this->startRoot($erasure, $userId);
        self::assertNull(AccountErasurePersonalCaptureResult::of($erasure->id, $userId));
        $assignmentId = $this->assignCapture($erasure, $userId);
        self::assertNull(AccountErasurePersonalCaptureResult::of($erasure->id, $userId));

        $this->runCapture($userId, $assignmentId);

        $capture = AccountErasurePersonalCaptureResult::of($erasure->id, $userId);
        self::assertNotNull($capture);
        self::assertSame(['+15550100', 'adam@example.test', 'zoe@example.test'], $capture->addresses);
        self::assertSame([], $capture->project);

        self::assertTrue($this->asHolder(static fn (): bool => $erasure->actions->advance(self::CAPTURE_STAGE, self::LATER_STAGE)));
        self::assertSame(self::LATER_STAGE, $erasure->stage);
        $later = AccountErasurePersonalCaptureResult::of($erasure->id, $userId);
        self::assertNotNull($later);
        self::assertSame(['+15550100', 'adam@example.test', 'zoe@example.test'], $later->addresses);
        self::assertSame([], $later->project);
    }

    /**
     * The coordinator hands out no capture before the start; after it, every account of the circle
     * gets its personal capture once, a repeat records nothing, and the census composition stays
     * as the opening left it.
     */
    public function testRecordAfterStartHandsOutAPersonalCaptureToEveryAccountOnlyAfterTheStart(): void
    {
        $rootId = $this->seedPerson();
        $foldedId = $this->seedPerson();
        self::seedMerge($foldedId, $rootId);
        $erasure = $this->openErasure($rootId);
        $composition = self::compositionOf($erasure->id);
        $stored = self::storedAssignments();

        try {
            $this->recordAfterStart($erasure);
            self::fail('The captures were handed out before the start');
        } catch (ValidationException $refusal) {
            self::assertSame('An erasure captures its anchors only after its start.', $refusal->getMessage());
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($stored, self::storedAssignments());

        $this->startRoot($erasure, $rootId);
        $recorded = $this->recordAfterStart($erasure);

        $owner = HilosAgentType::HILOS_USER;
        self::assertSame(
            [
                ["capture:personal:{$owner}:{$foldedId}:{$foldedId}", self::START_STAGE, $owner, (string)$foldedId, $foldedId, self::CAPTURE_PAYLOAD],
                ["capture:personal:{$owner}:{$rootId}:{$rootId}", self::START_STAGE, $owner, (string)$rootId, $rootId, self::CAPTURE_PAYLOAD],
            ],
            array_map(
                static fn (array $row): array => array_slice($row, 1),
                self::storedPersonalCaptures(),
            ),
        );
        self::assertSame(
            array_column(self::storedPersonalCaptures(), 0),
            array_map(static fn (AccountOperationAssignment $assignment): ?int => $assignment->id, $recorded),
        );

        self::assertSame([], $this->recordAfterStart($erasure));
        self::assertSame($composition, self::compositionOf($erasure->id));
    }

    /**
     * The coordinator reads the start in the table, past its memory: a start its memory has not
     * caught up with, the way one the root's agent committed in its own worker reaches the holder
     * only by sync, still lets the captures out.
     */
    public function testRecordAfterStartSeesAStartTheHoldersMemoryHasNotCaughtUpWith(): void
    {
        $rootId = $this->seedPerson();
        $erasure = $this->openErasure($rootId);
        self::assertNull(Hilos::$db->accountDeletions[$erasure->requestId]?->startedAt);
        Database::sqlRun('UPDATE `hilos_account_deletion` SET `started_at` = ? WHERE `id` = ?', [TimeHelper::nowMs(), $erasure->requestId]);
        self::assertNull(Hilos::$db->accountDeletions[$erasure->requestId]?->startedAt, 'The memory still holds the request unbegun');

        $recorded = $this->recordAfterStart($erasure);

        self::assertSame(
            [AccountErasureCapture::personalKey($rootId)],
            array_map(static fn (AccountOperationAssignment $assignment): string => $assignment->assignmentKey, $recorded),
        );
        self::assertSame(array_column(self::storedPersonalCaptures(), 0), [$recorded[0]->id]);
    }

    /**
     * The person's agent, answering the project's part with whatever the case holds at the moment
     * it is asked, and counting the asks.
     *
     * @param string $userId Person id as the agent address carries it
     * @return AbstractUserAgent The agent, not yet claimed
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new ErasureCaptureTestUserAgent($userId, function (int $userId): array {
            $this->projectPartCalls++;

            return ($this->projectPart)($userId);
        });
    }

    /**
     * Ways in that name three addresses, one of them twice: a password and a sign-in link on
     * one email, a password on another, and a phone.
     *
     * @param int $userId Person the ways in belong to
     */
    private static function seedAddresses(int $userId): void
    {
        self::seedIdentity($userId, IdentityType::PASSWORD, 'zoe@example.test');
        self::seedIdentity($userId, IdentityType::MAGIC_LINK, 'zoe@example.test');
        self::seedIdentity($userId, IdentityType::PASSWORD, 'adam@example.test');
        self::seedIdentity($userId, IdentityType::SMS, '+15550100');
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
        $request = $this->requestDeletion($rootId);

        return $this->asHolder(static function () use ($request): AccountOperation {
            $erasure = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->operation;
            self::assertNotNull($erasure);

            return $erasure;
        });
    }

    /**
     * @param int $userId Person whose account is to be deleted
     * @return AccountDeletion The request
     */
    private function requestDeletion(int $userId): AccountDeletion
    {
        $at = TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->users->getId()),
            static fn () => Hilos::$db->accountDeletions->actions->request($userId, $at),
        );
        $request = Hilos::$db->accountDeletions->liveOf($userId);
        self::assertNotNull($request);

        return $request;
    }

    /**
     * Closes the root account: the root's agent starts the erasure on its request.
     *
     * @param AccountOperation $erasure Open erasure of the root's request
     * @param int $rootId Root account
     */
    private function startRoot(AccountOperation $erasure, int $rootId): void
    {
        $assignmentId = $this->asHolder(static fn (): int => self::assign(
            $erasure,
            self::START_STAGE,
            self::START_KEY . $rootId,
            $rootId,
            $rootId,
            self::START_PAYLOAD,
        ));
        $result = $this->asPerson($rootId, static fn (): AccountOperationResult => AccountErasureStart::run($assignmentId));
        self::assertTrue(AccountErasureStartResult::startedOf($result->payload));
    }

    /**
     * Gives the personal capture of one account to that account's agent.
     *
     * @param AccountOperation $erasure Open erasure
     * @param int $userId Account of its circle
     * @return int Id of the capture's assignment
     */
    private function assignCapture(AccountOperation $erasure, int $userId): int
    {
        return $this->asHolder(static fn (): int => self::assign(
            $erasure,
            self::CAPTURE_STAGE,
            AccountErasureCapture::personalKey($userId),
            $userId,
            $userId,
            self::CAPTURE_PAYLOAD,
        ));
    }

    /**
     * Moves an open operation to a stage, unless it stands there already, and records one
     * assignment at it; runs in the session holder's frame.
     *
     * @param AccountOperation $operation Open operation
     * @param string $stage Stage the assignment is recorded at
     * @param string $key Assignment key
     * @param int $ownerId Person whose agent the assignment is written to
     * @param int $userId Account the assignment is about
     * @param string $payloadJson Assignment payload
     * @return int Id of the assignment
     */
    private static function assign(
        AccountOperation $operation,
        string $stage,
        string $key,
        int $ownerId,
        int $userId,
        string $payloadJson,
    ): int {
        if ($operation->stage !== $stage) {
            self::assertTrue($operation->actions->advance($operation->stage, $stage));
        }

        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $stage,
            $key,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$ownerId),
            $userId,
            AccountOperationAssignmentPayload::fromJson($payloadJson),
        )->id;
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
     * Hands out the captures of an erasure in the session holder's frame, the way the coordinator would.
     *
     * @param AccountOperation $erasure Open erasure
     * @return list<AccountOperationAssignment> Assignments written
     */
    private function recordAfterStart(AccountOperation $erasure): array
    {
        return $this->asHolder(
            static fn (): array => AccountErasureCapture::recordAfterStart($erasure, AccountErasureParticipantCatalog::framework()),
        );
    }

    /**
     * @param int $userId Account whose agent captures
     * @param int $assignmentId Capture's assignment
     * @return AccountOperationResult The recorded or saved capture
     */
    private function runCapture(int $userId, int $assignmentId): AccountOperationResult
    {
        return $this->asPerson(
            $userId,
            static fn (AbstractUserAgent $agent): AccountOperationResult => $agent->captureForErasure($assignmentId),
        );
    }

    /**
     * @param int $userId Account whose agent captures
     * @param int $assignmentId Capture's assignment
     * @param string $words Words of the refusal
     */
    private function assertCaptureRefused(int $userId, int $assignmentId, string $words): void
    {
        $before = self::storedCaptures();
        try {
            $this->runCapture($userId, $assignmentId);
            self::fail('The capture was not refused');
        } catch (ValidationException $refusal) {
            self::assertSame($words, $refusal->getMessage());
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($before, self::storedCaptures());
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
     * @return list<array{int, string, string}> Assignment, owner and payload of every stored result but the starts', in id order
     */
    private static function storedCaptures(): array
    {
        return array_map(
            static fn (array $row): array => [(int)$row['assignment_id'], (string)$row['owner_agent_id'], (string)$row['payload_json']],
            Database::sql(
                'SELECT `r`.`assignment_id`, `r`.`owner_agent_id`, `r`.`payload_json` FROM `hilos_account_operation_result` `r`'
                    . ' JOIN `hilos_account_operation_assignment` `a` ON `a`.`id` = `r`.`assignment_id`'
                    . ' WHERE `a`.`assignment_key` NOT LIKE ? ORDER BY `r`.`id`',
                [self::START_KEY . '%'],
            )->rows(),
        );
    }

    /**
     * @return list<array{int, string, string, string, ?string, int, string}> Id, key, stage, owner, account and payload
     *     of every stored personal capture, in id order
     */
    private static function storedPersonalCaptures(): array
    {
        return array_map(
            static fn (array $row): array => [
                (int)$row['id'],
                (string)$row['assignment_key'],
                (string)$row['stage'],
                (string)$row['owner_type'],
                $row['owner_index'] === null ? null : (string)$row['owner_index'],
                (int)$row['user_id'],
                (string)$row['payload_json'],
            ],
            Database::sql(
                'SELECT `id`, `assignment_key`, `stage`, `owner_type`, `owner_index`, `user_id`, `payload_json`'
                    . ' FROM `hilos_account_operation_assignment` WHERE `assignment_key` LIKE ? ORDER BY `id`',
                ['capture:personal:%'],
            )->rows(),
        );
    }

    /** @return list<array<string, mixed>> Every stored assignment, in id order */
    private static function storedAssignments(): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
    }

    /**
     * @param int $operationId Operation whose census composition is read
     * @return array{list<string>, list<string>} Keys of the expected owners and of the waits, in id order
     */
    private static function compositionOf(int $operationId): array
    {
        $set = AccountOperationExpectedSet::of($operationId);
        $keys = static fn (array $assignments): array => array_map(
            static fn (AccountOperationAssignment $assignment): string => $assignment->assignmentKey,
            $assignments,
        );

        return [$keys($set->expected()), $keys($set->waiting())];
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
final class ErasureCaptureTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_erasure_capture_users_library';
}

/** The framework's session holder, writing the account operations of a case. */
final class ErasureCaptureTestSessionsHolder extends AbstractSessionsLibraryAgent
{
}

/** The person's agent with the project's part of the capture handed in by the case. */
final class ErasureCaptureTestUserAgent extends AbstractUserAgent
{
    /**
     * @param string $agentIndex Person id from the agent address
     * @param Closure(int): array<string, mixed> $projectPart The project's part, asked with the person's id
     */
    public function __construct(string $agentIndex, private readonly Closure $projectPart)
    {
        parent::__construct($agentIndex);
    }

    /**
     * @param int $userId Person whose account is being erased
     * @return array<string, mixed> Whatever the case's part answers
     */
    protected function erasureCaptureProjectPart(int $userId): array
    {
        return ($this->projectPart)($userId);
    }
}
