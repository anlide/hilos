<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;

/**
 * The one door every database write asks before it touches a row.
 *
 * {@see TruthSourceRegistry} answers who owns a collection; this class is where the four
 * write paths - the table doors, the object save and delete, the collection-wide truncate
 * and the targeted raw UPDATEs - ask it, so the right stops depending on which of them a
 * caller happened to take.
 *
 * The right used to be asked inside a switch over the lazy-loading strategy, which answers a
 * different question - how much of a table has to be in memory - and so was only ever asked
 * of the four collections loaded whole. Every hot table is lazy, and every one of them was
 * written in silence. Asking here, once, is what closes that (HIL-716).
 *
 * It refuses by raising, and the refusal is the registry's own: this class adds no wording
 * and no exception type of its own. Before the claims were handed out it caught the refusal
 * and logged it instead, so one full run could name every write standing on nothing; that
 * scaffolding came off with the last unclaimed writer.
 */
class DbWriteGuard
{
    /**
     * Judges creating a row in a collection.
     *
     * @param string $collection Collection key, empty for a manual collection nobody owns
     * @throws CreateNotAllowedException When no grant in this process may add a row here
     */
    public static function guardCreate(string $collection): void
    {
        if ($collection === '') {
            return;
        }

        TruthSourceRegistry::checkCanCreate($collection);
    }

    /**
     * Judges a write that names no single row - a collection-wide one.
     *
     * @param string $collection Collection key, empty for a manual collection nobody owns
     * @throws WriteNotAllowedException When no grant in this process covers the whole collection
     */
    public static function guardCollectionWrite(string $collection): void
    {
        if ($collection === '') {
            return;
        }

        TruthSourceRegistry::checkCanWrite($collection);
    }

    /**
     * Judges one operation on one row that already exists.
     *
     * @param string $collection Collection key, empty for a manual collection nobody owns
     * @param string $idString Row id as string, composite keys joined with ':'
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @throws WriteNotAllowedException When no grant in this process covers that row and operation
     */
    public static function guardItemWrite(
        string $collection,
        string $idString,
        TruthSourceOperation $operation,
    ): void {
        if ($collection === '') {
            return;
        }

        TruthSourceRegistry::checkCanWriteItem($collection, $idString, $operation);
    }
}
