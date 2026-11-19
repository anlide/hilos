<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\AccountDeletion as EntityAccountDeletion;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;
use Hilos\Database\Entity\Item\AccountOperationResult as EntityAccountOperationResult;
use Hilos\Database\Entity\Item\UserMerge as EntityUserMerge;
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\Database\Object\Item\AccountOperation as ObjectAccountOperation;
use Hilos\Database\Object\Item\AccountOperationAssignment as ObjectAccountOperationAssignment;
use Hilos\Database\Object\Item\AccountOperationResult as ObjectAccountOperationResult;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\User;
use Hilos\Hilos;
use Hilos\HilosException;

/** Translates durable merge and erasure metadata into the accounts whose projections must be rebuilt. */
final class AccountMergeSourceChange
{
    private const array SOURCES = [
        HilosDbContext::accountOperations,
        HilosDbContext::accountOperationAssignments,
        HilosDbContext::accountOperationResults,
        HilosDbContext::accountDeletions,
        HilosDbContext::userMerges,
    ];

    /**
     * @param SourceChange $change Current metadata fact, including previous values when carried
     * @return list<int> Distinct affected accounts, conservatively all current people for an unresolvable fact
     * @throws HilosException When a mounted reader refuses the fact or its relations
     */
    public static function userIds(SourceChange $change): array
    {
        if (!$change->isDb() || !in_array($change->sourceKey, self::SOURCES, true)) {
            return [];
        }
        if ($change->mutationType === TableMutationType::Clear) {
            return self::allUserIds();
        }
        $rows = [self::metadataObjectRow($change->sourceKey, $change->row), self::metadataObjectRow($change->sourceKey, $change->previous)];
        $ids = [];
        $complete = true;
        if ($change->sourceKey === HilosDbContext::accountOperations) {
            $operation = Hilos::$db->accountOperations[(int) $change->sourceId] ?? null;
            $complete = $operation !== null;
            if ($operation !== null) {
                $ids = self::operationUserIds($operation);
            }
            foreach ($rows as $row) {
                foreach ([ObjectAccountOperation::rootUserId, ObjectAccountOperation::loserUserId,
                    ObjectAccountOperation::survivorUserId] as $field) {
                    if (isset($row[$field])) {
                        $ids[] = (int) $row[$field];
                    }
                }
                // A removed or changed circle cannot be recovered from the current bridge alone.
                if (isset($row[ObjectAccountOperation::circleUserIds])
                    && ($operation === null || $row[ObjectAccountOperation::circleUserIds] !== $operation->circleUserIds)) {
                    $complete = false;
                }
            }
        } elseif ($change->sourceKey === HilosDbContext::accountOperationAssignments) {
            $assignment = Hilos::$db->accountOperationAssignments[(int) $change->sourceId] ?? null;
            $operationIds = $assignment === null ? [] : [$assignment->operationId];
            foreach ($rows as $row) {
                if (isset($row[ObjectAccountOperationAssignment::userId])) {
                    $ids[] = (int) $row[ObjectAccountOperationAssignment::userId];
                }
                if (isset($row[ObjectAccountOperationAssignment::operationId])) {
                    $operationIds[] = (int) $row[ObjectAccountOperationAssignment::operationId];
                }
            }
            $complete = $operationIds !== [];
            foreach (array_unique($operationIds) as $operationId) {
                $operation = Hilos::$db->accountOperations[$operationId] ?? null;
                if ($operation === null) {
                    $complete = false;
                } else {
                    array_push($ids, ...self::operationUserIds($operation));
                }
            }
        } elseif ($change->sourceKey === HilosDbContext::accountOperationResults) {
            $result = Hilos::$db->accountOperationResults[(int) $change->sourceId] ?? null;
            $assignmentIds = $result === null ? [] : [$result->assignmentId];
            foreach ($rows as $row) {
                if (isset($row[ObjectAccountOperationResult::assignmentId])) {
                    $assignmentIds[] = (int) $row[ObjectAccountOperationResult::assignmentId];
                }
            }
            $complete = $assignmentIds !== [];
            foreach (array_unique($assignmentIds) as $assignmentId) {
                $assignment = Hilos::$db->accountOperationAssignments[$assignmentId] ?? null;
                $operation = $assignment?->operation;
                if ($operation === null) {
                    $complete = false;
                } else {
                    if ($assignment->userId !== null) {
                        $ids[] = $assignment->userId;
                    }
                    array_push($ids, ...self::operationUserIds($operation));
                }
            }
        } elseif ($change->sourceKey === HilosDbContext::accountDeletions) {
            $deletion = Hilos::$db->accountDeletions[(int) $change->sourceId] ?? null;
            if ($deletion !== null) {
                $ids[] = $deletion->userId;
            }
            foreach ($rows as $row) {
                if (isset($row[ObjectAccountDeletion::userId])) {
                    $ids[] = (int) $row[ObjectAccountDeletion::userId];
                }
            }
            $complete = $ids !== [];
        } else {
            foreach ($rows as $row) {
                foreach ([ObjectUserMerge::userId, ObjectUserMerge::survivorUserId] as $field) {
                    if (isset($row[$field])) {
                        $ids[] = (int) $row[$field];
                    }
                }
            }
            $merge = Hilos::$db->userMerges[(int) $change->sourceId] ?? null;
            if ($merge !== null) {
                $ids[] = $merge->userId;
                if ($merge->survivorUserId !== null) {
                    $ids[] = $merge->survivorUserId;
                }
            }
            if (ctype_digit($change->sourceId) && (int) $change->sourceId > 0) {
                $ids[] = (int) $change->sourceId;
            }
            $complete = $merge !== null || isset($rows[0][ObjectUserMerge::survivorUserId], $rows[0][ObjectUserMerge::userId]);
        }
        if (!$complete || $ids === []) {
            array_push($ids, ...self::allUserIds());
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Update facts carry persisted Entity keys; create/delete facts may already carry Object keys.
     *
     * @param string $source Metadata collection whose relation fields are read
     * @param array<string, mixed> $row Current or previous source values
     * @return array<string, mixed> Source values with the mapper's Object relation keys
     */
    private static function metadataObjectRow(string $source, array $row): array
    {
        $fields = match ($source) {
            HilosDbContext::accountOperations => [
                EntityAccountOperation::root_user_id => ObjectAccountOperation::rootUserId,
                EntityAccountOperation::loser_user_id => ObjectAccountOperation::loserUserId,
                EntityAccountOperation::circle_user_ids => ObjectAccountOperation::circleUserIds,
            ],
            HilosDbContext::accountOperationAssignments => [
                EntityAccountOperationAssignment::operation_id => ObjectAccountOperationAssignment::operationId,
                EntityAccountOperationAssignment::user_id => ObjectAccountOperationAssignment::userId,
            ],
            HilosDbContext::accountOperationResults => [
                EntityAccountOperationResult::assignment_id => ObjectAccountOperationResult::assignmentId,
            ],
            HilosDbContext::accountDeletions => [EntityAccountDeletion::user_id => ObjectAccountDeletion::userId],
            default => [
                EntityUserMerge::user_id => ObjectUserMerge::userId,
                EntityUserMerge::survivor_user_id => ObjectUserMerge::survivorUserId,
            ],
        };
        foreach ($fields as $entityField => $objectField) {
            if (array_key_exists($entityField, $row)) {
                $row[$objectField] = $row[$entityField];
            }
        }

        return $row;
    }

    /**
     * @param AccountOperation $operation Mounted operation with its checked circle bridge
     * @return list<int> Pair and circle, with duplicates removed by the caller
     * @throws HilosException When the operation or its checked circle cannot be read
     */
    private static function operationUserIds(AccountOperation $operation): array
    {
        $ids = $operation->circle->toArray();
        $ids[] = $operation->rootUserId;
        if ($operation->loserUserId !== null) {
            $ids[] = $operation->loserUserId;
        }
        if ($operation->survivorUserId !== null) {
            $ids[] = $operation->survivorUserId;
        }

        return $ids;
    }

    /**
     * @return list<int> Current persisted people, independent of the partial lazy mirror
     * @throws HilosException When the complete user query fails
     */
    private static function allUserIds(): array
    {
        $result = Hilos::$db->users->queryPageItems(new TableQueryDTO());

        return array_map(static fn (User $user): int => (int) $user->id, $result[TableConstants::RESULT_KEY_ROWS]);
    }
}
