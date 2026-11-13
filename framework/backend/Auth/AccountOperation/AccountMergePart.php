<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/**
 * The part of an account merge one project owner is asked to carry: the survivor and the loser of
 * one operation, as the operation records them.
 * SCAFFOLD: no live caller until the merge coordinator asks its owners (HIL-2000), live at the
 * merge switch (HIL-1999).
 */
final readonly class AccountMergePart
{
    /**
     * @param int $operationId Merge operation the part belongs to
     * @param int $survivorUserId Account that receives the owner's rows
     * @param int $loserUserId Account whose rows the owner moves
     */
    public function __construct(
        public int $operationId,
        public int $survivorUserId,
        public int $loserUserId,
    ) {
    }
}
