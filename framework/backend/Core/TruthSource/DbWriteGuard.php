<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;

/**
 * The one door every database write asks before it touches a row.
 *
 * {@see TruthSourceRegistry} answers who owns a collection; this class is where the five
 * write paths - the table doors, the object save and delete, the collection-wide truncate,
 * the targeted raw UPDATEs and one statement over the rows of one set - ask it, so the right
 * stops depending on which of them a caller happened to take.
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
     * @param TruthSourceOperation $operation Operation the caller is about to perform across the whole collection
     * @throws WriteNotAllowedException When no grant in this process covers the whole collection with that operation
     */
    public static function guardCollectionWrite(string $collection, TruthSourceOperation $operation): void
    {
        if ($collection === '') {
            return;
        }

        TruthSourceRegistry::checkCanWrite($collection, $operation);
    }

    /**
     * Judges one statement over every row of one set.
     *
     * The table is cut by the set column its entity declares, and the door is handed only the
     * value the statement cuts by: the writer does not pick the column. A statement that rewrites
     * the set column itself writes into two sets and asks {@see guardCollectionWrite()} instead -
     * only the owner of the whole table moves rows between sets.
     *
     * @param string $collection Collection key, empty for a manual collection nobody owns
     * @param string $setKey Value of the set column the statement cuts the table by, empty for nobody's set
     * @param TruthSourceOperation $operation Operation the caller is about to perform on every row of the set
     * @throws WriteNotAllowedException When no grant in this process covers every row of that set with that operation
     */
    public static function guardSetWrite(string $collection, string $setKey, TruthSourceOperation $operation): void
    {
        if ($collection === '') {
            return;
        }

        TruthSourceRegistry::checkCanWriteSet($collection, $setKey, $operation);
    }

    /**
     * Judges one operation on one row that already exists.
     *
     * The door hands over, beside the row's id, the set keys the write touches, because a claim
     * over a set is answered by the row's set column and not by its id: the key the row is stored
     * under, and the one an unsaved edit moves it to. Each is named once, so a write that keeps
     * the row in its set names one key and a move between two sets names both.
     *
     * @param string $collection Collection key, empty for a manual collection nobody owns
     * @param string $idString Row id as string, composite keys joined with ':'
     * @param list<string> $setKeys Set keys the write touches, each once; empty for a row outside every set
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @throws WriteNotAllowedException When no grant in this process covers that row and operation
     */
    public static function guardItemWrite(
        string $collection,
        string $idString,
        array $setKeys,
        TruthSourceOperation $operation,
    ): void {
        if ($collection === '') {
            return;
        }

        TruthSourceRegistry::checkCanWriteItem($collection, $idString, $setKeys, $operation);
    }
}
