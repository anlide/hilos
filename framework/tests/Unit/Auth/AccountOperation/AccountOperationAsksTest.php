<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Error;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalRefusedException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureUncapturedException;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\Object\Item\AccountOperationAssignment as ObjectAccountOperationAssignment;
use Hilos\Database\View\Collection\AccountOperationAssignments;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class AccountOperationAsksTest extends TestCase
{
    private ?HilosDbContext $previousDb;

    /** Preserve the process facade while each case supplies a storage double. */
    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
    }

    /** Restore the process facade after the case. */
    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
    }

    /** @return array<string, array{?int, string}> Missing assignment and mismatched correlation fields */
    public static function foreignAssignments(): array
    {
        return ['missing' => [null, 'parts'], 'other operation' => [8, 'parts'], 'other stage' => [7, 'final']];
    }

    /**
     * @param ?int $operationId Stored operation, or null for a missing assignment
     * @param string $stage Stored stage
     */
    #[DataProvider('foreignAssignments')]
    public function testMissingOrForeignAssignmentDoesNotRunTheStep(?int $operationId, string $stage): void
    {
        $this->mount($operationId === null ? null : self::assignment($operationId, $stage));

        $answer = AccountOperationAsks::answer(
            new AccountOperationAskSignalData(7, 'parts', 11),
            static function (AccountOperationAssignment $assignment): AccountOperationResult {
                self::fail('A missing or foreign assignment must not run its step');
            },
            HilosAgentType::HILOS_USERS_LIBRARY,
        );

        self::assertSame(self::wire('refused'), $answer->toArray());
    }

    /** A returned result completes the ask after its callback receives the stored assignment exactly once. */
    public function testStepReceivesTheMatchedAssignmentAndAResultAnswersDone(): void
    {
        $assignment = self::assignment(7, 'parts');
        $this->mount($assignment);
        $result = $this->createStub(AccountOperationResult::class);
        $calls = 0;

        $answer = AccountOperationAsks::answer(
            new AccountOperationAskSignalData(7, 'parts', 11),
            static function (AccountOperationAssignment $received) use ($assignment, $result, &$calls): AccountOperationResult {
                ++$calls;
                self::assertSame($assignment, $received);

                return $result;
            },
            HilosAgentType::HILOS_USERS_LIBRARY,
        );

        self::assertSame(1, $calls);
        self::assertSame(self::wire('done'), $answer->toArray());
    }

    /** @return array<string, array{Throwable, string, ?int, ?string}> Domain refusals translated without their words */
    public static function refusals(): array
    {
        $foreignKey = new ForeignKeyConstraintException('private engine refusal', ForeignKeyConstraintException::ROW_IS_REFERENCED_ERROR_CODE);

        return [
            'uncaptured addresses' => [AccountErasureUncapturedException::addresses(), 'uncaptured', null, null],
            'uncaptured deliveries' => [AccountErasureUncapturedException::deliveries([5]), 'uncaptured', null, null],
            'waits for participant' => [
                AccountErasureFinalWaitsException::of([AccountErasureParticipantCatalog::PERSONAL], []), 'waits', null, null,
            ],
            'waits for folded account' => [AccountErasureFinalWaitsException::of([], [42]), 'waits', null, null],
            'final refusal with table' => [
                AccountErasureFinalRefusedException::of(42, 'project_note', $foreignKey), 'final_refused', 42, 'project_note',
            ],
            'final refusal without table' => [AccountErasureFinalRefusedException::of(42, null, $foreignKey), 'final_refused', 42, null],
            'validation refusal' => [new ValidationException('private validation refusal'), 'refused', null, null],
            'step refusal' => [new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment), 'refused', null, null],
        ];
    }

    /**
     * @param Throwable $refusal Participant's refusal
     * @param string $kind Expected answer
     * @param ?int $userId Refused final's account
     * @param ?string $table Refused final's known referencing table
     */
    #[DataProvider('refusals')]
    public function testDomainRefusalBecomesItsSafeAnswer(Throwable $refusal, string $kind, ?int $userId, ?string $table): void
    {
        $this->mount(self::assignment(7, 'parts'));

        $answer = AccountOperationAsks::answer(
            new AccountOperationAskSignalData(7, 'parts', 11),
            static function (AccountOperationAssignment $assignment) use ($refusal): AccountOperationResult {
                throw $refusal;
            },
            HilosAgentType::HILOS_USERS_LIBRARY,
        );

        self::assertSame(self::wire($kind, $userId, $table), $answer->toArray());
        self::assertEquals($answer, AccountOperationAnswerSignalData::fromArray($answer->toArray()));
    }

    /** @return array<string, array{Throwable, bool}> Participant failures and a refused storage read */
    public static function failures(): array
    {
        return [
            'exception' => [new RuntimeException('private exception text'), false],
            'error' => [new Error('private error text'), false],
            'wiring refusal' => [new DbCollectionNotReadableException('private wiring refusal'), true],
        ];
    }

    /**
     * @param Throwable $failure Unexpected participant or storage failure
     * @param bool $duringRead Whether storage fails before the callback can run
     */
    #[DataProvider('failures')]
    public function testUnexpectedFailureIsLoggedForTheAgentAndNeverCarriedInTheAnswer(Throwable $failure, bool $duringRead): void
    {
        if ($duringRead) {
            $db = $this->createMock(HilosDbContext::class);
            $db->method('__get')->with(HilosDbContext::accountOperationAssignments)->willThrowException($failure);
            Hilos::$db = $db;
        } else {
            $this->mount(self::assignment(7, 'parts'));
        }
        $calls = 0;
        $logFile = tempnam(sys_get_temp_dir(), 'account-operation-ask-');
        self::assertNotFalse($logFile);
        $mainLogProperty = new ReflectionProperty(Logger::class, 'logFile');
        $errorLogProperty = new ReflectionProperty(Logger::class, 'errorLogFile');
        $previousMainLog = $mainLogProperty->getValue();
        $previousErrorLog = $errorLogProperty->getValue();
        Logger::resetLogFile();
        Logger::setErrorLogFile($logFile);
        try {
            $answer = AccountOperationAsks::answer(
                new AccountOperationAskSignalData(7, 'parts', 11),
                static function (AccountOperationAssignment $assignment) use ($failure, &$calls): AccountOperationResult {
                    ++$calls;
                    throw $failure;
                },
                HilosAgentType::HILOS_USERS_LIBRARY,
            );

            self::assertSame($duringRead ? 0 : 1, $calls);
            self::assertSame(self::wire('failed'), $answer->toArray());
            $log = file_get_contents($logFile);
            self::assertNotFalse($log);
            self::assertStringContainsString(HilosAgentType::HILOS_USERS_LIBRARY, $log);
            self::assertStringContainsString($failure->getMessage(), $log);
        } finally {
            $mainLogProperty->setValue(null, $previousMainLog);
            $errorLogProperty->setValue(null, $previousErrorLog);
            unlink($logFile);
        }
    }

    /** @param ?AccountOperationAssignment $assignment Row the storage double returns, or absence */
    private function mount(?AccountOperationAssignment $assignment): void
    {
        $assignments = $this->createMock(AccountOperationAssignments::class);
        $assignments->method('offsetExists')->with(11)->willReturn($assignment !== null);
        if ($assignment === null) {
            $assignments->expects(self::never())->method('offsetGet');
        } else {
            $assignments->method('offsetGet')->with(11)->willReturn($assignment);
        }
        $db = $this->createMock(HilosDbContext::class);
        $db->method('__get')->with(HilosDbContext::accountOperationAssignments)->willReturn($assignments);
        Hilos::$db = $db;
    }

    /**
     * @param int $operationId Stored parent operation
     * @param string $stage Stored stage
     * @return AccountOperationAssignment Immutable assignment without a database connection
     */
    private static function assignment(int $operationId, string $stage): AccountOperationAssignment
    {
        $entity = EntityAccountOperationAssignment::getEmpty();
        $entity->id = 11;
        $entity->operation_id = $operationId;
        $entity->stage = $stage;
        $entity->assignment_key = 'erase-notes';
        $entity->owner_type = HilosAgentType::HILOS_USERS_LIBRARY;
        $entity->payload_json = '{}';
        $entity->created_at = 1790416800000;

        return new AccountOperationAssignment(ObjectAccountOperationAssignment::fromEntity($entity));
    }

    /**
     * @param string $answer Literal expected wire answer
     * @param ?int $userId Account of a final refusal
     * @param ?string $table Known referencing table
     * @return array<string, mixed> Complete expected answer, with no exception words
     */
    private static function wire(string $answer, ?int $userId = null, ?string $table = null): array
    {
        return [
            'operationId' => 7,
            'stage' => 'parts',
            'assignmentId' => 11,
            'answer' => $answer,
            'refusedUserId' => $userId,
            'refusedTable' => $table,
        ];
    }
}
