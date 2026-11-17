<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Database\Context\HilosDbContext;

/** Attempt-local mapped SQL rows and their current transfer metadata; never a durable receipt or private source copy. */
final readonly class AccountMergeReceiptSnapshot
{
    public const array COLLECTIONS = [
        HilosDbContext::identities, HilosDbContext::passkeyCredentials, HilosDbContext::secondFactors,
        HilosDbContext::secondFactorBackupCodes, HilosDbContext::secondFactorSettings,
    ];

    /**
     * The nested arrays remain at the mounted Entity row boundary, keyed by collection and primary key.
     *
     * @param int $operationId Current open merge
     * @param int $giveAssignmentId Original give obligation
     * @param int $survivorUserId Canonical executing person
     * @param array<string, array<int, array<string, mixed>>> $rows Current own mapped Entity rows
     * @param array<string, AccountMergeFenceSnapshot> $fences Current metadata for the original manifest
     */
    public function __construct(
        public int $operationId,
        public int $giveAssignmentId,
        public int $survivorUserId,
        public array $rows,
        public array $fences,
    ) {
    }
}
