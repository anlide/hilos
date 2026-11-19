<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/**
 * The part of an account erasure one project owner is asked to carry: one account of one operation.
 */
final readonly class AccountErasurePart
{
    /**
     * @param int $operationId Erasure operation the part belongs to
     * @param int $userId Account of the erasure circle whose rows the owner erases
     */
    public function __construct(
        public int $operationId,
        public int $userId,
    ) {
    }
}
