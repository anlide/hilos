<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\Transaction;

use Hilos\Core\Exception\LogicException;
use Throwable;

/**
 * Exception: a step putting this process's memory back on a rollback raised something outside
 * the framework's exception tree.
 *
 * The framework's own steps raise Hilos exceptions and reach the caller of the rollback as they
 * are; this wraps the rest - an Error, a foreign exception out of a project's onRollback()
 * closure - so the rollback keeps the one contract its callers catch by. The original failure
 * is kept in previous.
 */
class MemoryRollbackFailedException extends LogicException
{
    /**
     * @param Throwable $previous What the step putting memory back raised
     */
    public function __construct(Throwable $previous)
    {
        parent::__construct(
            'A step restoring this process\'s memory on a rollback failed: ' . $previous->getMessage(),
            previous: $previous,
        );
    }
}
