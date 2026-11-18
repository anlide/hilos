<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeCleanupResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\AccountOperationAssignments;
use Hilos\Database\View\Collection\AccountOperationResults;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact nonsecret cleanup and its immutable storage address. */
final class AccountMergeCleanupResultTest extends TestCase
{
    /** The successful cleanup is the same exact object through every boundary. */
    public function testCleanedRoundTrip(): void
    {
        $cleanup = AccountMergeCleanupResult::cleaned();
        self::assertSame('{"cleanup":"cleaned"}', $cleanup->toJson());
        self::assertSame(['cleanup' => 'cleaned'], $cleanup->toArray());
        self::assertSame($cleanup->toArray(), AccountMergeCleanupResult::of($cleanup->toPayload())->toArray());
        self::assertSame($cleanup->toArray(), AccountMergeCleanupResult::fromArray($cleanup->toArray())->toArray());
    }

    /** @return iterable<string, array{string}> Wrong roots, words, types and secret-bearing extensions */
    public static function invalidForms(): iterable
    {
        foreach (['{}', '[]', 'null', 'true', '1', '"cleaned"', '{"cleanup":"waiting"}', '{"cleanup":null}',
            '{"cleanup":true}', '{"cleanup":1}', '{"cleanup":1.0}', '{"cleanup":[]}', '{"cleanup":{}}',
            '{"cleaned":"cleaned"}', '{"cleanup":"cleaned","secret":"hidden"}',
            '{"cleanup":"cleaned","assignmentId":3}'] as $json) {
            yield $json => [$json];
        }
    }

    /** @param string $json Nonexact cleanup */
    #[DataProvider('invalidForms')]
    public function testNonexactCleanupIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeCleanupResult::fromJson($json);
    }

    /** Malformed JSON is reported through the existing JSON error family. */
    public function testMalformedJsonIsRefused(): void
    {
        $this->expectException(InvalidJsonException::class);
        AccountMergeCleanupResult::fromJson('{');
    }

    /** @return iterable<string, array{string}> Every independent immutable assignment or result coordinate */
    public static function bindings(): iterable
    {
        foreach (['exact', 'missing assignment', 'missing result', 'operation kind', 'missing survivor', 'missing loser',
            'zero survivor', 'zero loser', 'same accounts', 'operation id', 'stage', 'duty', 'user', 'owner type',
            'owner index', 'assignment payload', 'result assignment', 'result owner', 'result payload'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /**
     * The query identity and result lookup are asserted independently of the returned row coordinates.
     * No current-by-user lookup or current stage is needed to read an old cleanup.
     *
     * @param string $defect Single invalid storage coordinate, absence, or exact successful cleanup
     */
    #[DataProvider('bindings')]
    public function testCleanupRequiresItsCanonicalAssignmentAndResult(string $defect): void
    {
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '9');
        $operationFields = ['id' => 2, 'kind' => AccountOperationKind::Merge, 'loserUserId' => 9, 'survivorUserId' => 7];
        $assignmentFields = [
            'id' => 3, 'operationId' => 2, 'stage' => AccountMergeStage::Go->value, 'assignmentKey' => AccountMergeDuty::Cleanup->value,
            'userId' => 9, 'owner' => $owner, 'payload' => AccountOperationAssignmentPayload::fromJson(' { } '),
        ];
        $resultFields = [
            'assignmentId' => 3, 'ownerAgentId' => $owner->agentId(), 'payload' => AccountMergeCleanupResult::cleaned()->toPayload(),
        ];
        switch ($defect) {
            case 'operation kind': $operationFields['kind'] = AccountOperationKind::Erasure; break;
            case 'missing survivor': $operationFields['survivorUserId'] = null; break;
            case 'missing loser': $operationFields['loserUserId'] = null; break;
            case 'zero survivor': $operationFields['survivorUserId'] = 0; break;
            case 'zero loser': $operationFields['loserUserId'] = 0; break;
            case 'same accounts': $operationFields['loserUserId'] = 7; break;
            case 'operation id': $assignmentFields['operationId'] = 4; break;
            case 'stage': $assignmentFields['stage'] = AccountMergeStage::Preparing->value; break;
            case 'duty': $assignmentFields['assignmentKey'] = AccountMergeDuty::Give->value; break;
            case 'user': $assignmentFields['userId'] = 7; break;
            case 'owner type': $assignmentFields['owner'] = new AccountOperationOwnerAddress('foreign', '9'); break;
            case 'owner index': $assignmentFields['owner'] = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '7'); break;
            case 'assignment payload': $assignmentFields['payload'] = AccountOperationAssignmentPayload::fromJson('{"extra":true}'); break;
            case 'result assignment': $resultFields['assignmentId'] = 4; break;
            case 'result owner': $resultFields['ownerAgentId'] = HilosAgentType::HILOS_USER . ':7'; break;
            case 'result payload': $resultFields['payload'] = AccountOperationResultPayload::fromJson('{}'); break;
        }
        $operation = $this->createStub(AccountOperation::class);
        $operation->method('__get')->willReturnCallback(static fn (string $key): mixed => $operationFields[$key]);
        $assignment = $this->createStub(AccountOperationAssignment::class);
        $assignment->method('__get')->willReturnCallback(static fn (string $key): mixed => $assignmentFields[$key]);
        $result = $this->createStub(AccountOperationResult::class);
        $result->method('__get')->willReturnCallback(static fn (string $key): mixed => $resultFields[$key]);
        $invalidOperation = in_array($defect, [
            'operation kind', 'missing survivor', 'missing loser', 'zero survivor', 'zero loser', 'same accounts',
        ], true);
        $assignments = $this->createMock(AccountOperationAssignments::class);
        $assignments->expects($invalidOperation ? self::never() : self::once())->method('byIdentity')
            ->with(2, AccountMergeStage::Go->value, AccountMergeDuty::Cleanup->value)
            ->willReturn($defect === 'missing assignment' ? null : $assignment);
        $results = $this->createMock(AccountOperationResults::class);
        $readsResult = in_array($defect, ['exact', 'missing result', 'result assignment', 'result owner', 'result payload'], true);
        $results->expects($readsResult ? self::once() : self::never())->method('byAssignment')->with(3)
            ->willReturn($defect === 'missing result' ? null : $result);
        $db = $this->createStub(HilosDbContext::class);
        $db->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            HilosDbContext::accountOperationAssignments => $assignments,
            HilosDbContext::accountOperationResults => $results,
        });
        $previousDb = Hilos::$db;
        Hilos::$db = $db;
        try {
            if (!in_array($defect, ['exact', 'missing assignment', 'missing result'], true)) {
                $this->expectException(InvalidFormatException::class);
            }
            $cleanup = AccountMergeCleanupResult::forOperation($operation);
            if ($defect === 'exact') {
                self::assertSame(['cleanup' => 'cleaned'], $cleanup->toArray());
            } else {
                self::assertNull($cleanup);
            }
        } finally {
            Hilos::$db = $previousDb;
        }
    }
}
