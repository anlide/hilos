<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupSpaceDecision - the verdict {@see BackupSpaceGuard} returns for one run.
 *
 * Immutable value object: whether the run may proceed, the free space that was available, the
 * bytes the run was required to have, and - when refused - the ready-made failure reason. The
 * reason is already the string {@see BackupCreator::recordFailure()} stores on the error sidecar,
 * so the agent threads it straight through without reformatting; it is null for an allowed run.
 */
final class BackupSpaceDecision
{
    /**
     * @param bool $allowed Whether the run may proceed
     * @param int $requiredBytes Free bytes the run was required to have (the binding of estimate and floor)
     * @param int $freeBytes Free bytes measured at decision time
     * @param ?string $reason Ready-made failure reason when refused; null when allowed
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly int $requiredBytes,
        public readonly int $freeBytes,
        public readonly ?string $reason = null,
    ) {
    }
}
