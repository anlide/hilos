<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;

/**
 * What one erasure opening came to: the operation it opened or found, or the open erasure it was
 * deferred behind, and the assignments it wrote.
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952).
 */
final readonly class AccountErasureOpeningResult
{
    /**
     * @param ?AccountOperation $operation Opened or already recorded erasure, null when deferred
     * @param ?int $deferredByOperationId Open erasure already covering an account of the circle, null when not deferred
     * @param list<AccountOperationAssignment> $recorded Assignments this call wrote, in census order
     */
    public function __construct(
        public ?AccountOperation $operation,
        public ?int $deferredByOperationId,
        public array $recorded,
    ) {
    }
}
