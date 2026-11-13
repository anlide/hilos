<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountErasureCapture;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureStart;
use Hilos\Auth\AccountOperation\AccountErasureStartResult;
use Hilos\Auth\AccountOperation\AccountErasureVerificationsResult;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\TimeConstants;
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
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The users library's part of an account erasure, carried as its participant step (HIL-1944).
 *
 * The step is called the way the coordinator will call it: in the library's own frame, with the
 * assignment the census writes for the users library participant, on an erasure the session holder
 * opened and whose personal capture the person's agent has recorded. One transaction deletes the
 * codes on the captured addresses, whoever they carry, and the codes carrying the person's id, and
 * records how many it deleted; a separate connection sees the deletion and the result together.
 * Every write runs in the frame of the agent whose class declares it, and commits for real.
 */
final class AccountErasureVerificationsParticipantIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Tables of the account operations and the codes, raised over the base's. */
    private const array TABLES = [
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
        'hilos_user_verification',
    ];

    private const int PEER_INDEX = 2;

    /** Stage the case gives the start at: the start's own stage is not this leaf's. */
    private const string START_STAGE = 'starting';

    /** Stage the case gives the capture at: the capture's own stage is HIL-1950's. */
    private const string CAPTURE_STAGE = 'capturing';

    /** Stage the case gives the library's part at, after the capture: the stages are HIL-1950's. */
    private const string PART_STAGE = 'deleting';

    /** Prefix of the start's assignment key, followed by the account. */
    private const string START_KEY = 'start:';

    /** Payload of the start's assignment, which the start does not read. */
    private const string START_PAYLOAD = '{}';

    /** Payload of the personal capture's assignment. */
    private const string CAPTURE_PAYLOAD = '{"participant":"' . AccountErasureParticipantCatalog::PERSONAL . '"}';

    private ErasureVerificationsTestLibrary $library;

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        self::stubs(down: true);
        self::stubs(down: false);
        OwnershipDeclaration::claimDb(ErasureVerificationsTestCoordinator::class, HilosAgentType::HILOS_SESSIONS_LIBRARY);
        $this->library = new ErasureVerificationsTestLibrary();
        OwnershipDeclaration::claimAll($this->library);
        $this->connectPeer();
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(down: true);
        } finally {
            $this->releasePersonAgents();
            foreach ([$this->library->getId(), HilosAgentType::HILOS_SESSIONS_LIBRARY] as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                RtTruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();

            parent::tearDown();
        }
    }

    /**
     * With the ways in already gone, the captured addresses still lead to their codes: the codes on
     * them, whoever they carry or none, and the person's own codes anywhere go, five in all. The
     * neighbour's code on their own address and a code nobody carries on an address the capture
     * does not name stay; a separate connection sees the deletion and the result together.
     */
    public function testTheCodesOnTheCapturedAddressesAndOfThePersonGoWithTheirCount(): void
    {
        $userId = $this->seedPerson();
        $neighbourId = $this->seedPerson();
        self::seedIdentity($userId, IdentityType::PASSWORD, 'zoe@example.test');
        self::seedIdentity($userId, IdentityType::SMS, '+15550100');
        $assignmentId = $this->capturedPart($userId);
        $this->asPerson($userId, static fn () => Hilos::$db->identities->deleteForUser($userId));
        self::seedVerification($userId, 'zoe@example.test');
        self::seedVerification($userId, 'old@example.test');
        self::seedVerification(null, 'zoe@example.test');
        self::seedVerification(null, '+15550100');
        self::seedVerification($neighbourId, '+15550100');
        self::seedVerification($neighbourId, 'neighbour@example.test');
        self::seedVerification(null, 'stranger@example.test');

        $result = $this->erase($assignmentId);

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame('{"verifications":5}', $result->payloadJson);
        self::assertSame(5, AccountErasureVerificationsResult::deletedOf($result->payload));
        self::assertSame(
            [[$neighbourId, 'neighbour@example.test'], [null, 'stranger@example.test']],
            self::storedVerifications(),
        );
        self::assertSame([[$assignmentId, '{"verifications":5}']], self::storedLibraryResults());
    }

    /**
     * A repeat after the commit answers with the saved result and deletes nothing: a code laid on a
     * captured address between the two calls stays.
     */
    public function testARepeatAnswersTheSavedResultAndDeletesNothing(): void
    {
        $userId = $this->seedPerson();
        self::seedIdentity($userId, IdentityType::PASSWORD, 'zoe@example.test');
        $assignmentId = $this->capturedPart($userId);
        self::seedVerification($userId, 'zoe@example.test');
        $first = $this->erase($assignmentId);
        self::seedVerification(null, 'zoe@example.test');

        $repeat = $this->erase($assignmentId);

        self::assertSame($first->id, $repeat->id);
        self::assertSame('{"verifications":1}', $repeat->payloadJson);
        self::assertSame([[$assignmentId, '{"verifications":1}']], self::storedLibraryResults());
        self::assertSame([[null, 'zoe@example.test']], self::storedVerifications());
    }

    /** A person without a single code is answered with none. */
    public function testAPersonWithoutCodesIsAnsweredWithNone(): void
    {
        $userId = $this->seedPerson();
        self::seedIdentity($userId, IdentityType::PASSWORD, 'zoe@example.test');
        $assignmentId = $this->capturedPart($userId);
        self::seedVerification(null, 'stranger@example.test');

        $result = $this->erase($assignmentId);

        self::assertSame('{"verifications":0}', $result->payloadJson);
        self::assertSame([[null, 'stranger@example.test']], self::storedVerifications());
        self::assertSame([[$assignmentId, '{"verifications":0}']], self::storedLibraryResults());
    }

    /** Before the person's agent has captured the addresses the part waits, and touches no code. */
    public function testWithoutTheCaptureThePartWaits(): void
    {
        $userId = $this->seedPerson();
        self::seedIdentity($userId, IdentityType::PASSWORD, 'zoe@example.test');
        $erasure = $this->openErasure($userId);
        $this->startRoot($erasure, $userId);
        $assignmentId = $this->libraryPart($erasure, self::PART_STAGE, $userId);
        self::seedVerification($userId, 'zoe@example.test');
        self::seedVerification(null, 'zoe@example.test');

        $this->assertRefused($assignmentId, 'A users library erasure part waits for the personal capture of its account.');
    }

    /**
     * The part of an account folded into the root takes the addresses from that account's own
     * capture: a code on the root's address, which that capture does not name, stays.
     */
    public function testAFoldedAccountsPartTakesTheAddressesOfItsOwnCapture(): void
    {
        $rootId = $this->seedPerson();
        self::seedIdentity($rootId, IdentityType::PASSWORD, 'root@example.test');
        $foldedId = $this->seedPerson();
        self::seedIdentity($foldedId, IdentityType::PASSWORD, 'folded@example.test');
        self::seedMerge($foldedId, $rootId);
        $erasure = $this->openErasure($rootId);
        $this->startRoot($erasure, $rootId);
        $this->runCapture($foldedId, $this->assignCapture($erasure, $foldedId));
        $assignmentId = $this->libraryPart($erasure, self::PART_STAGE, $foldedId);
        self::seedVerification(null, 'folded@example.test');
        self::seedVerification($foldedId, 'folded@example.test');
        self::seedVerification(null, 'root@example.test');
        self::seedVerification($rootId, 'root@example.test');

        $result = $this->erase($assignmentId);

        self::assertSame('{"verifications":2}', $result->payloadJson);
        self::assertSame([[null, 'root@example.test'], [$rootId, 'root@example.test']], self::storedVerifications());
    }

    /** The part asked of the library in the session holder's frame is somebody else's assignment there. */
    public function testAnotherAgentsFrameIsRefusedTheAssignment(): void
    {
        $userId = $this->seedPerson();
        self::seedIdentity($userId, IdentityType::PASSWORD, 'zoe@example.test');
        $assignmentId = $this->capturedPart($userId);
        self::seedVerification($userId, 'zoe@example.test');
        self::seedVerification(null, 'zoe@example.test');
        $before = self::storedVerifications();

        try {
            $this->asCoordinator(fn (): AccountOperationResult => $this->library->runErasurePart($assignmentId));
            self::fail('The part was carried in a foreign frame');
        } catch (AccountOperationStepRefusedException $refusal) {
            self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $refusal->reason);
        }

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($before, self::storedVerifications());
        self::assertSame([], self::storedLibraryResults());
    }

    /** A users library part assigned inside a merge is refused before any work. */
    public function testAPartAssignedInAMergeIsRefused(): void
    {
        $userId = $this->seedPerson();
        $survivorId = $this->seedPerson();
        self::seedVerification($userId, 'zoe@example.test');
        $merge = $this->asCoordinator(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordMerge(
            $survivorId,
            $userId,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        ));
        $assignmentId = $this->libraryPart($merge, $merge->stage, $userId);

        $this->assertRefused($assignmentId, 'A users library erasure part must belong to an erasure.');
    }

    /** A users library part written without an account is refused: there is nobody whose codes to delete. */
    public function testAPartNamingNoAccountIsRefused(): void
    {
        $userId = $this->seedPerson();
        self::seedVerification($userId, 'zoe@example.test');
        $erasure = $this->openErasure($userId);
        $assignmentId = $this->libraryPart($erasure, $erasure->stage, null);

        $this->assertRefused($assignmentId, 'A users library erasure part must name its account.');
    }

    /**
     * @param int $assignmentId Assignment the library is asked to carry
     * @param string $words Words of the refusal
     */
    private function assertRefused(int $assignmentId, string $words): void
    {
        $before = self::storedVerifications();
        try {
            $this->erase($assignmentId);
            self::fail('The part was not refused');
        } catch (ValidationException $refusal) {
            self::assertNotInstanceOf(AccountOperationStepRefusedException::class, $refusal);
            self::assertSame($words, $refusal->getMessage());
        }

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($before, self::storedVerifications());
        self::assertSame([], self::storedLibraryResults());
    }

    /**
     * Opens the erasure of the person alone, closes the account, records the person's capture and
     * gives the library its part after it.
     *
     * @param int $userId Account to erase
     * @return int Id of the library's assignment
     */
    private function capturedPart(int $userId): int
    {
        $erasure = $this->openErasure($userId);
        $this->startRoot($erasure, $userId);
        $this->runCapture($userId, $this->assignCapture($erasure, $userId));

        return $this->libraryPart($erasure, self::PART_STAGE, $userId);
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

        return $this->asCoordinator(static function () use ($request): AccountOperation {
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
            new ExecutionFrame(agentId: $this->library->getId()),
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
        $assignmentId = $this->asCoordinator(static fn (): int => self::assign(
            $erasure,
            self::START_STAGE,
            self::START_KEY . $rootId,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$rootId),
            $rootId,
            AccountOperationAssignmentPayload::fromJson(self::START_PAYLOAD),
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
        return $this->asCoordinator(static fn (): int => self::assign(
            $erasure,
            self::CAPTURE_STAGE,
            AccountErasureCapture::personalKey($userId),
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
            $userId,
            AccountOperationAssignmentPayload::fromJson(self::CAPTURE_PAYLOAD),
        ));
    }

    /**
     * Records the users library part of an account on an operation, with the key, owner and payload
     * the census writes for the operation's root, and the account the case asks for; runs in the
     * session holder's frame.
     *
     * @param AccountOperation $operation Open operation
     * @param string $stage Stage the part is recorded at
     * @param ?int $userId Account the part names, or null for none
     * @return int Id of the assignment
     */
    private function libraryPart(AccountOperation $operation, string $stage, ?int $userId): int
    {
        $expectation = new AccountOperationExpectation(
            AccountErasureParticipantCatalog::USERS_LIBRARY,
            $userId ?? $operation->rootUserId,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USERS_LIBRARY),
        );

        return $this->asCoordinator(static fn (): int => self::assign(
            $operation,
            $stage,
            $expectation->assignmentKey(),
            $expectation->assignmentOwner(),
            $userId,
            $expectation->payload(),
        ));
    }

    /**
     * Moves an open operation to a stage, unless it stands there already, and records one
     * assignment at it; runs in the session holder's frame.
     *
     * @param AccountOperation $operation Open operation
     * @param string $stage Stage the assignment is recorded at
     * @param string $key Assignment key
     * @param AccountOperationOwnerAddress $owner Agent the assignment is written to
     * @param ?int $userId Account the assignment is about, or null for none
     * @param AccountOperationAssignmentPayload $payload Assignment payload
     * @return int Id of the assignment
     */
    private static function assign(
        AccountOperation $operation,
        string $stage,
        string $key,
        AccountOperationOwnerAddress $owner,
        ?int $userId,
        AccountOperationAssignmentPayload $payload,
    ): int {
        if ($operation->stage !== $stage) {
            self::assertTrue($operation->actions->advance($operation->stage, $stage));
        }

        return Hilos::$db->accountOperationAssignments->actions->record($operation->id, $stage, $key, $owner, $userId, $payload)->id;
    }

    /**
     * @param int $userId Account whose agent captures
     * @param int $assignmentId Capture's assignment
     */
    private function runCapture(int $userId, int $assignmentId): void
    {
        $this->asPerson($userId, static fn (AbstractUserAgent $agent): AccountOperationResult => $agent->captureForErasure($assignmentId));
    }

    /**
     * @param int $assignmentId Assignment the library carries
     * @return AccountOperationResult The recorded or saved result
     */
    private function erase(int $assignmentId): AccountOperationResult
    {
        return ExecutionContext::run(
            new ExecutionFrame(agentId: $this->library->getId()),
            fn (): AccountOperationResult => $this->library->runErasurePart($assignmentId),
        );
    }

    /**
     * Runs one write in the session holder's frame, which opens the erasure and writes its assignments.
     *
     * @template T
     * @param callable(): T $write Write to run as the holder
     * @return T Whatever the write returns
     */
    private function asCoordinator(callable $write): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY), $write);
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
     * @param ?int $userId Person the code carries, or null
     * @param string $identifier Address the code was issued on
     */
    private static function seedVerification(?int $userId, string $identifier): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user_verification` (`user_id`, `type`, `identifier`, `expires_at`, `created_at`) '
            . 'VALUES (?, ?, ?, 2082758400000, 1790416800000)',
            [$userId, VerificationType::ACCOUNT_DELETION, $identifier],
        );
    }

    /**
     * @return list<array{?int, string}> Person and address of every stored code, as a separate connection sees them
     */
    private static function storedVerifications(): array
    {
        return array_map(
            static fn (array $row): array => [$row['user_id'] === null ? null : (int)$row['user_id'], (string)$row['identifier']],
            Database::sql(
                'SELECT `user_id`, `identifier` FROM `hilos_user_verification` ORDER BY `identifier`, `user_id`',
                index: self::PEER_INDEX,
            )->rows(),
        );
    }

    /**
     * @return list<array{int, string}> Assignment and payload of every result stored under the library, as a separate connection sees them
     */
    private static function storedLibraryResults(): array
    {
        return array_map(
            static fn (array $row): array => [(int)$row['assignment_id'], (string)$row['payload_json']],
            Database::sql(
                'SELECT `assignment_id`, `payload_json` FROM `hilos_account_operation_result` WHERE `owner_agent_id` = ? ORDER BY `id`',
                [HilosAgentType::HILOS_USERS_LIBRARY],
                index: self::PEER_INDEX,
            )->rows(),
        );
    }

    /** Connects a separate SQL observer, which sees only what was committed. */
    private function connectPeer(): void
    {
        $primary = Database::getConnectionConfig(0);
        Database::configure(self::PEER_INDEX, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);
        Database::connect(self::PEER_INDEX);
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

/** The framework's users library under its own address, with nothing overridden. */
final class ErasureVerificationsTestLibrary extends AbstractUsersLibraryAgent
{
}

/** The session holder, which opens the erasure and writes its assignments. */
final class ErasureVerificationsTestCoordinator extends AbstractSessionsLibraryAgent
{
}
