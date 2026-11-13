<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountErasureDataExportResult;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
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
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\DataExport\DataExportState;
use Hilos\DataExport\DataExportStateProjector;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The data copy agent's part of an account erasure, carried as its participant step (HIL-1946).
 *
 * The step is called the way the coordinator will call it: in the agent's own frame, with the
 * assignment the census writes for the data copy participant, on an erasure the session holder
 * opened. One transaction deletes the person's copy order and records how many it deleted; the
 * files go only once it commits, by the agent's own sweep, and a separate connection sees the
 * deletion and the result together. Another person's ready copy is in every case, and stays.
 */
final class AccountErasureDataExportParticipantIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const int USER_ID = 1946;
    private const int NEIGHBOUR_ID = 1947;
    private const int REQUEST_ID = 19460;
    private const int PEER_INDEX = 2;

    /** Tables of the account operations, raised over the base's. */
    private const array TABLES = [
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    /** 2036-01-01 00:00:00: no copy of a case expires while it runs. */
    private const string EXPIRES_AT = '2036-01-01 00:00:00';

    private const string PERSON_COPY = 'person.zip';
    private const string INTERRUPTED_BUILD = 'person.building.zip';
    private const string NEIGHBOUR_COPY = 'neighbour.zip';

    private string $directory;

    private ErasureDataExportTestAgent $agent;

    private ?FsContext $previousFs = null;

    private ?SignalRouter $previousRouter = null;

    /**
     * @throws HilosException When the schema, the copy directory or the claims cannot be prepared
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(down: true);
        self::stubs(down: false);
        Schema::reset();
        Schema::initialize();
        Database::sqlRun(
            'INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?), (?, ?)',
            [self::USER_ID, 'Person', self::NEIGHBOUR_ID, 'Neighbour'],
        );

        $this->previousRouter = Hilos::$sr;
        $this->previousFs = Hilos::$fs;
        Hilos::$sr = new SignalRouter();
        $this->directory = sys_get_temp_dir() . '/hilos-data-export-erasure-' . bin2hex(random_bytes(8));
        Hilos::$fs = new ErasureDataExportTestFs($this->directory);
        Hilos::$fs->configure();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());

        OwnershipDeclaration::claimDb(ErasureDataExportTestCoordinator::class, HilosAgentType::HILOS_SESSIONS_LIBRARY);
        $this->agent = new ErasureDataExportTestAgent();
        OwnershipDeclaration::claimAll($this->agent);
        $this->agent->onStart();
        $this->connectPeer();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(down: true);
            Schema::reset();
        } finally {
            foreach ([$this->agent->getId(), HilosAgentType::HILOS_SESSIONS_LIBRARY] as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            SourceChangeBus::reset();
            Hilos::$fs = $this->previousFs;
            Hilos::$sr = $this->previousRouter;
            foreach (glob($this->directory . '/*') as $path) {
                unlink($path);
            }
            if (is_file(ClusterDirectoryMarker::pathIn($this->directory))) {
                unlink(ClusterDirectoryMarker::pathIn($this->directory));
            }
            if (is_dir($this->directory)) {
                rmdir($this->directory);
            }

            parent::tearDown();
        }
    }

    /** @return iterable<string, array{string, bool}> The person's order state, and whether a build was cut off beside it */
    public static function orderStates(): iterable
    {
        yield 'preparing' => [DataExportState::PREPARING, false];
        yield 'preparing, an interrupted build on disk' => [DataExportState::PREPARING, true];
        yield 'ready, its copy on disk' => [DataExportState::READY, false];
        yield 'failed' => [DataExportState::FAILED, false];
    }

    /**
     * Whatever state the person's order is in, it is deleted with its result; after the commit only
     * the neighbour's copy is left on disk, and the person's tabs are told there is no copy.
     *
     * @param string $state State of the person's order
     * @param bool $interruptedBuild Whether a build cut off before publication lies in the directory
     * @throws HilosException When a fixture cannot be written or the step fails
     */
    #[DataProvider('orderStates')]
    public function testThePersonsOrderIsDeletedWithItsResultAndTheFilesGoAfterTheCommit(string $state, bool $interruptedBuild): void
    {
        $this->seedNeighbourCopy();
        $this->seedPersonOrder($state);
        if ($interruptedBuild) {
            file_put_contents($this->directory . '/' . self::INTERRUPTED_BUILD, 'partial bytes');
        }
        $assignmentId = $this->exportPart($this->openErasure(), self::USER_ID);

        $result = $this->erase($assignmentId);

        self::assertSame('{"orders":1}', $result->payloadJson);
        self::assertSame(1, AccountErasureDataExportResult::ordersOf($result->payload));
        self::assertSame([self::NEIGHBOUR_ID], self::storedOrderOwners());
        self::assertSame([[$assignmentId, $this->agent->getId(), '{"orders":1}']], self::storedResults());
        self::assertSame([$this->directory . '/' . self::NEIGHBOUR_COPY], glob($this->directory . '/*'));
        self::assertSame(DataExportState::READY, Hilos::$db->dataExports->ofUser(self::NEIGHBOUR_ID)?->state);
        self::assertSame([null], $this->agent->published);
        self::assertSame([], $this->agent->errors);
    }

    /**
     * A person who never ordered a copy is answered with no orders, and the neighbour's copy stays.
     *
     * @throws HilosException When a fixture cannot be written or the step fails
     */
    public function testAPersonWithoutAnOrderIsAnsweredWithNone(): void
    {
        $this->seedNeighbourCopy();
        $assignmentId = $this->exportPart($this->openErasure(), self::USER_ID);

        $result = $this->erase($assignmentId);

        self::assertSame('{"orders":0}', $result->payloadJson);
        self::assertSame(0, AccountErasureDataExportResult::ordersOf($result->payload));
        self::assertSame([self::NEIGHBOUR_ID], self::storedOrderOwners());
        self::assertSame([[$assignmentId, $this->agent->getId(), '{"orders":0}']], self::storedResults());
        self::assertSame([$this->directory . '/' . self::NEIGHBOUR_COPY], glob($this->directory . '/*'));
        self::assertSame(DataExportState::READY, Hilos::$db->dataExports->ofUser(self::NEIGHBOUR_ID)?->state);
    }

    /**
     * A repeat answers with the saved result and does not sweep again: a file laid in the directory
     * between the two calls stays.
     *
     * @throws HilosException When a fixture cannot be written or the step fails
     */
    public function testARepeatAnswersTheSavedResultAndDoesNothing(): void
    {
        $this->seedPersonOrder(DataExportState::READY);
        $assignmentId = $this->exportPart($this->openErasure(), self::USER_ID);
        $first = $this->erase($assignmentId);
        file_put_contents($this->directory . '/' . self::INTERRUPTED_BUILD, 'partial bytes');

        $repeat = $this->erase($assignmentId);

        self::assertSame($first->id, $repeat->id);
        self::assertSame('{"orders":1}', $repeat->payloadJson);
        self::assertSame([[$assignmentId, $this->agent->getId(), '{"orders":1}']], self::storedResults());
        self::assertSame([$this->directory . '/' . self::INTERRUPTED_BUILD], glob($this->directory . '/*'));
        self::assertSame([null], $this->agent->published);
    }

    /**
     * The part asked of the agent in the session holder's frame is somebody else's assignment there.
     *
     * @throws HilosException When a fixture cannot be written or read
     */
    public function testAnotherAgentsFrameIsRefusedTheAssignment(): void
    {
        $this->seedPersonOrder(DataExportState::READY);
        $assignmentId = $this->exportPart($this->openErasure(), self::USER_ID);

        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
                fn (): AccountOperationResult => $this->agent->runErasurePart($assignmentId),
            );
            self::fail('The part was carried in a foreign frame');
        } catch (AccountOperationStepRefusedException $refusal) {
            self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $refusal->reason);
        }

        $this->assertNothingWritten();
    }

    /**
     * A data copy part assigned inside a merge is refused before any work.
     *
     * @throws HilosException When a fixture cannot be written or read
     */
    public function testAPartAssignedInAMergeIsRefused(): void
    {
        $this->seedPersonOrder(DataExportState::READY);
        $merge = $this->asCoordinator(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordMerge(
            self::NEIGHBOUR_ID,
            self::USER_ID,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        ));
        $assignmentId = $this->exportPart($merge, self::USER_ID);

        $this->assertRefused($assignmentId, 'A data export erasure part must belong to an erasure.');
    }

    /**
     * A data copy part written without an account is refused: there is nobody whose order to delete.
     *
     * @throws HilosException When a fixture cannot be written or read
     */
    public function testAPartNamingNoAccountIsRefused(): void
    {
        $this->seedPersonOrder(DataExportState::READY);
        $assignmentId = $this->exportPart($this->openErasure(), null);

        $this->assertRefused($assignmentId, 'A data export erasure part must name its account.');
    }

    /**
     * @param int $assignmentId Assignment the agent is asked to carry
     * @param string $words Words of the refusal
     * @throws HilosException When a row cannot be read
     */
    private function assertRefused(int $assignmentId, string $words): void
    {
        try {
            $this->erase($assignmentId);
            self::fail('The part was not refused');
        } catch (ValidationException $refusal) {
            self::assertNotInstanceOf(AccountOperationStepRefusedException::class, $refusal);
            self::assertSame($words, $refusal->getMessage());
        }

        $this->assertNothingWritten();
    }

    /**
     * The person's ready order and its copy are where the fixture left them, and no result is stored.
     *
     * @throws HilosException When a row cannot be read
     */
    private function assertNothingWritten(): void
    {
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([self::USER_ID], self::storedOrderOwners());
        self::assertSame(DataExportState::READY, Hilos::$db->dataExports->ofUser(self::USER_ID)?->state);
        self::assertSame([$this->directory . '/' . self::PERSON_COPY], glob($this->directory . '/*'));
        self::assertSame([], self::storedResults());
        self::assertSame([], $this->agent->published);
    }

    /**
     * @param string $state State the person's order is brought to; a ready one has its copy on disk
     * @throws HilosException When the order cannot be written
     */
    private function seedPersonOrder(string $state): void
    {
        $order = Hilos::$db->dataExports->actions->order(self::USER_ID, '2026-01-01 00:00:00');
        if ($state === DataExportState::READY) {
            self::assertTrue($order->actions->finishReady(
                self::PERSON_COPY,
                13,
                '2026-01-01 00:01:00',
                self::EXPIRES_AT,
                TimeConstants::UTC_TIME_ZONE,
            ));
            file_put_contents($this->directory . '/' . self::PERSON_COPY, 'person bytes');
        }
        if ($state === DataExportState::FAILED) {
            self::assertTrue($order->actions->finishFailed('2026-01-01 00:01:00', self::EXPIRES_AT));
        }
        self::assertSame($state, Hilos::$db->dataExports->ofUser(self::USER_ID)?->state);
    }

    /**
     * Gives the neighbour a ready copy whose file lies in the directory.
     *
     * @throws HilosException When the order cannot be written
     */
    private function seedNeighbourCopy(): void
    {
        Hilos::$db->dataExports->actions->order(self::NEIGHBOUR_ID, '2026-01-01 00:00:00')->actions->finishReady(
            self::NEIGHBOUR_COPY,
            15,
            '2026-01-01 00:01:00',
            self::EXPIRES_AT,
            TimeConstants::UTC_TIME_ZONE,
        );
        file_put_contents($this->directory . '/' . self::NEIGHBOUR_COPY, 'neighbour bytes');
    }

    /**
     * @return AccountOperation Erasure of the account alone, written by the session holder
     * @throws HilosException When the operation cannot be written
     */
    private function openErasure(): AccountOperation
    {
        return $this->asCoordinator(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordErasure(
            self::REQUEST_ID,
            self::USER_ID,
            Hilos::$db->userMerges->erasureCircleOf(self::USER_ID),
        ));
    }

    /**
     * Records the data copy part of the person on an operation, with the key, owner and payload
     * the census writes, and the account the case asks for; runs in the session holder's frame.
     *
     * @param AccountOperation $operation Operation to record on, at its current stage
     * @param ?int $userId Account the part names, or null for none
     * @return int Id of the assignment
     * @throws HilosException When the record refuses
     */
    private function exportPart(AccountOperation $operation, ?int $userId): int
    {
        $expectation = new AccountOperationExpectation(
            AccountErasureParticipantCatalog::DATA_EXPORT,
            self::USER_ID,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_DATA_EXPORT),
        );

        return $this->asCoordinator(static fn (): int => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            $expectation->assignmentKey(),
            $expectation->assignmentOwner(),
            $userId,
            $expectation->payload(),
        )->id);
    }

    /**
     * @param int $assignmentId Assignment the agent carries
     * @return AccountOperationResult The recorded or saved result
     * @throws HilosException When the step refuses or fails
     */
    private function erase(int $assignmentId): AccountOperationResult
    {
        return ExecutionContext::run(
            new ExecutionFrame(agentId: $this->agent->getId()),
            fn (): AccountOperationResult => $this->agent->runErasurePart($assignmentId),
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
     * @return list<int> Owners of the stored copy orders, as a separate connection sees them
     * @throws DatabaseException When the query fails
     */
    private static function storedOrderOwners(): array
    {
        return array_map(
            static fn (array $row): int => (int)$row['user_id'],
            Database::sql('SELECT `user_id` FROM `hilos_data_export` ORDER BY `user_id`', index: self::PEER_INDEX)->rows(),
        );
    }

    /**
     * @return list<array{int, string, string}> Assignment, owner and payload of every stored result, as a separate connection sees them
     * @throws DatabaseException When the query fails
     */
    private static function storedResults(): array
    {
        return array_map(
            static fn (array $row): array => [(int)$row['assignment_id'], (string)$row['owner_agent_id'], (string)$row['payload_json']],
            Database::sql(
                'SELECT `assignment_id`, `owner_agent_id`, `payload_json` FROM `hilos_account_operation_result` ORDER BY `id`',
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
     * @throws DatabaseException When a stub statement fails
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

/** A fresh copy directory for each case. */
final class ErasureDataExportTestFs extends FsContext
{
    /** @param string $path Private test directory */
    public function __construct(private readonly string $path)
    {
    }

    /** Registers the copy directory. */
    public function configure(): void
    {
        $this->registerDirectory(self::DATA_EXPORT, $this->path, DirectoryScope::CLUSTER);
    }
}

/** The session holder, which opens the erasure and writes its assignments. */
final class ErasureDataExportTestCoordinator extends AbstractSessionsLibraryAgent
{
}

/** The framework's data copy agent, with its publications and error lines caught. */
final class ErasureDataExportTestAgent extends AbstractDataExportAgent
{
    /** @var list<?array<string, mixed>> State publications captured at the group boundary */
    public array $published = [];
    /** @var list<string> Error lines */
    public array $errors = [];

    /** @param string $message Message the agent logged */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * @param int $userId Person whose state is published
     * @throws HilosException When the published state cannot be read
     */
    protected function publishState(int $userId): void
    {
        $this->published[] = DataExportStateProjector::stateFor($userId)->dataExport;
    }
}
