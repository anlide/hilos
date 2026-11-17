<?php

declare(strict_types=1);

namespace Hilos\Database;

/** Exact durable coordinates, independent of the local ORM's applied progress. */
final readonly class AccountMergeFenceSnapshot
{
    /**
     * @param string $collectionKey Stored collection_key metadata
     * @param int $rowId Stored row_id metadata
     * @param int $operationId Stored operation_id metadata
     * @param int $assignmentId Stored assignment_id metadata
     * @param int $ownerUserId Stored owner_user_id metadata
     * @param int $revision Stored revision metadata
     * @param bool $deleted Stored deleted metadata
     */
    public function __construct(
        public string $collectionKey,
        public int $rowId,
        public int $operationId,
        public int $assignmentId,
        public int $ownerUserId,
        public int $revision,
        public bool $deleted,
    ) {
    }
}
