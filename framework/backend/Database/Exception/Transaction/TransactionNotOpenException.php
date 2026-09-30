<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\Transaction;

use Hilos\Core\Exception\LogicException;

/**
 * Exception: a commit was asked of a connection with no open transaction to commit.
 *
 * Loud on purpose: the caller believes its writes are saved, and they either went out one by
 * one in autocommit or vanished with a connection that was closed. A rollback in the same
 * position is silent, like ROLLBACK in MySQL — it loses nothing.
 */
class TransactionNotOpenException extends LogicException
{
    /**
     * @param int $index Connection index the commit was asked on
     * @return self Refusal for a connection with no transaction at all
     */
    public static function forEmptyStack(int $index): self
    {
        return new self("No open transaction to commit on connection {$index}");
    }

    /**
     * @param int $index Connection index the failed level is on
     * @param int $depth Depth of the level that failed
     * @return self Refusal for a level already rolled back - by its failed commit or with its closed connection
     */
    public static function forFailedLevel(int $index, int $depth): self
    {
        return new self(
            "The transaction at depth {$depth} on connection {$index} is already rolled back - its commit did not"
            . ' stand, or its connection was closed; roll it back to close it',
        );
    }
}
