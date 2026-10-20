<?php

declare(strict_types=1);

namespace Hilos\Database\Transaction;

use Closure;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Database\Exception\Transaction\TransactionLeftOpenException;

/** A process-local capability to roll back exactly the test scope that issued it. */
final class TestRollbackScope
{
    /**
     * @internal Only Database supplies the closure that owns its transaction roots.
     * @param ?Closure(self): void $cleanup Identity-checked cleanup, consumed even on failure
     */
    public function __construct(private ?Closure $cleanup)
    {
    }

    /**
     * Repeated cleanup is harmless, including after a cleanup that reported a failure.
     *
     * @throws TestRollbackRefusedException When ownership or cleanup fails
     * @throws TransactionLeftOpenException When application levels were left open, after cleaning every connection
     */
    public function rollback(): void
    {
        $cleanup = $this->cleanup;
        $this->cleanup = null;
        if ($cleanup !== null) {
            $cleanup($this);
        }
    }
}
