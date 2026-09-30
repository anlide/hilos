<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\Transaction;

use Hilos\Core\Exception\LogicException;
use Hilos\Database\Database;

/**
 * Exception: a transaction was started while one is already open on the connection, and the
 * chain does not allow nesting.
 *
 * A plain {@see Database::transactionStart()} never nests. A nested level opens only when
 * every level of the chain, the new one included, was started with
 * {@see Database::transactionStartNestable()}. The open transaction is left as it was: the
 * caller's own catch rolls it back.
 */
class NestedTransactionRefusedException extends LogicException
{
    /**
     * @param int $index Connection index the open transaction is on
     * @param int $depth Depth of the open transaction
     * @param ?int $unmarkedLevel Depth of the open level started without the nestable mark, or null when the refused start itself lacks it
     * @return self Refusal naming the level that forbids nesting
     */
    public static function forStart(int $index, int $depth, ?int $unmarkedLevel): self
    {
        return new self(
            "A transaction is already open on connection {$index} at depth {$depth}; a nested start needs every level"
            . ' started with Database::transactionStartNestable(), and '
            . ($unmarkedLevel === null ? 'this start was not' : "level {$unmarkedLevel} was not"),
        );
    }

    /**
     * @param int $index Connection index the failed level is on
     * @param int $depth Depth of the level that failed
     * @return self Refusal naming the level the caller has yet to roll back
     */
    public static function forFailedLevel(int $index, int $depth): self
    {
        return new self(
            "A transaction is already open on connection {$index} at depth {$depth}; it is already rolled back - its"
            . ' commit did not stand, or its connection was closed - so roll it back to close it before starting another',
        );
    }
}
