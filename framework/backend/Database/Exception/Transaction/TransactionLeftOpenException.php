<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\Transaction;

use Hilos\Core\Exception\LogicException;
use Throwable;

/**
 * Exception: a handler ended with a transaction still open on one of its connections.
 *
 * A transaction lives inside the handler that opened it — one unit of a worker's tick, one
 * CLI command. Left open, it would take in every later write the process makes and be
 * committed by an unrelated BEGIN. The framework rolls it back at the end of the handler,
 * drops the announcements it held and raises this so the handler's failure is on record.
 */
class TransactionLeftOpenException extends LogicException
{
    /**
     * @param list<string> $descriptions One line per connection: its index, depth and dropped announcements
     * @param ?Throwable $rollbackFailure Failure of the rollback the framework made, when it made one and it failed
     * @return self Failure naming every connection a transaction was left open on
     */
    public static function forConnections(array $descriptions, ?Throwable $rollbackFailure): self
    {
        return new self(implode('; ', $descriptions), previous: $rollbackFailure);
    }

    /**
     * @param int $index Connection index the transaction was left open on
     * @param int $depth Depth the stack had reached
     * @param int $heldCount How many announcements were dropped with it
     * @return string One line of the message, for one connection
     */
    public static function describe(int $index, int $depth, int $heldCount): string
    {
        return "A transaction was left open on connection {$index} at depth {$depth}; it was rolled back and its"
            . " {$heldCount} held announcements were dropped";
    }
}
