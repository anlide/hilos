<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Closure;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos;

/**
 * SetTree - the walk from a row's set column up to the key at the top of its table's set tree.
 *
 * A table's set is cut by its `_setVia` column, and that column may point at a table that sits in
 * a set of its own: a passkey credential lies in the set of its identity anchor, the anchor in the
 * set of its person. A claim over a set is laid by the key at the top of that tree - the person,
 * the room - so a row two floors down belongs to the owner of the top, and this class climbs to it.
 *
 * The parent of a set column is the table its `_foreign` entry names; no second declaration says
 * it. The climb ends where there is nowhere further to go: a column with no `_foreign` entry is a
 * soft reference and its value is the top, and so is the value of one that names a table whose
 * rows belong to nobody's set. An Entity that declares `_setShortPath` carries the top on the row
 * itself, and its rows are not walked at all ({@see Object_::touchedSetKeys()}).
 *
 * The parent row is read through the guarded entrance an agent reads through
 * ({@see DbContext::getObjectCollection()}), by the pointer the parent has
 * stored: the truth of a parent is the table, not an unsaved edit in this process. That is why an
 * agent claiming a set of a walked table has to read every table of the climb, which
 * {@see TopologyValidator} holds at its start. A parent that is gone, or a table that is not
 * mounted, raises nothing - the row reaches no top and belongs to nobody's set.
 *
 * Only statics and no state between calls: the map of mounted tables is asked again each time,
 * because the climb runs only when a claim over a set judges a write, and the context it reads
 * is the one the process holds at that moment. The walk is finite because a chain of set columns
 * that returns to its own table refuses the startup of a node ({@see SetOwnershipGuard}).
 */
final class SetTree
{
    /**
     * Climbs one value of a table's `_setVia` column to the key at the top of the set tree.
     *
     * @param class-string<Entity> $entityClass Entity whose `_setVia` column holds the value
     * @param string $setKey Value of that column on a row
     * @return ?string Key at the top of the tree, null when the table is in no set or the value
     *     reaches no top - its parent row is gone or its parent table is not mounted here
     * @throws DbCollectionNotReadableException When this process does not read a table the climb passes through
     * @throws LogicException When the collection of a parent table has no entity collection configured
     * @throws DatabaseException When loading a parent row fails
     */
    public static function topOfSetKey(string $entityClass, string $setKey): ?string
    {
        $column = self::setColumnOf($entityClass);
        if ($column === null) {
            return null;
        }

        $parentTable = self::foreignOf($entityClass)[$column] ?? null;
        if ($parentTable === null) {
            return $setKey;
        }

        $mounted = self::mountedByTable();
        if (!isset($mounted[$parentTable])) {
            return null;
        }

        [$collectionKey, $parentClass] = $mounted[$parentTable];
        if (self::setColumnOf($parentClass) === null) {
            return $setKey;
        }

        // Read by the index and not with `??`: the null-coalescing form asks offsetExists(), which
        // answers from memory alone and would leave a lazy parent row unread.
        $collection = Hilos::$db?->getObjectCollection($collectionKey);
        if ($collection === null) {
            return null;
        }

        return $collection[$setKey]?->storedSetTop();
    }

    /**
     * The climb of one value of a table's `_setVia` column, handed to a write door uncalled.
     *
     * The form the statement over one set gives the guard ({@see DbWriteGuard::guardSetWrite()}):
     * the owner of the whole table never asks for it, and only a claim over a set pays for a parent
     * row being read.
     *
     * @param class-string<Entity> $entityClass Entity whose `_setVia` column holds the value
     * @param string $setKey Value of that column the statement cuts the table by
     * @return Closure(): list<string> Key at the top of the set tree the value reaches, empty when it reaches none
     */
    public static function climb(string $entityClass, string $setKey): Closure
    {
        return static function () use ($entityClass, $setKey): array {
            $top = self::topOfSetKey($entityClass, $setKey);

            return $top === null ? [] : [$top];
        };
    }

    /**
     * Names the tables the value of a table's `_setVia` column climbs through, bottom to top.
     *
     * A table is named when the climb reads a row of it: the tops - a soft reference, a table in
     * nobody's set - are not. A table that is not mounted here ends the list, with no collection.
     * Reads the declarations and the map of mounted collections alone, without a query.
     *
     * @param class-string<Entity> $entityClass Entity whose set column starts the climb
     * @return array<string, ?string> Mounted collection key of each table the climb reads, keyed by
     *     table name, null for a table that is not mounted; empty when the value is the top already
     */
    public static function walkOf(string $entityClass): array
    {
        $mounted = self::mountedByTable();
        $walk = [];
        $class = $entityClass;
        while (($column = self::setColumnOf($class)) !== null) {
            $parentTable = self::foreignOf($class)[$column] ?? null;
            if ($parentTable === null || array_key_exists($parentTable, $walk)) {
                break;
            }

            if (!isset($mounted[$parentTable])) {
                $walk[$parentTable] = null;

                break;
            }

            [$collectionKey, $class] = $mounted[$parentTable];
            if (self::setColumnOf($class) === null) {
                break;
            }

            $walk[$parentTable] = $collectionKey;
        }

        return $walk;
    }

    /**
     * @param class-string<Entity> $entityClass Entity to read
     * @return ?string Column its set is cut by, null when it declares none or its rows belong to nobody's set
     */
    public static function setColumnOf(string $entityClass): ?string
    {
        $name = "{$entityClass}::" . Entity::META_SET_VIA;
        if (!defined($name)) {
            return null;
        }

        $column = constant($name);

        return $column === Entity::SET_STANDALONE ? null : $column;
    }

    /**
     * @param class-string<Entity> $entityClass Entity to read
     * @return ?string Column that carries the key at the top of the set tree directly, null when none is declared
     */
    public static function shortPathOf(string $entityClass): ?string
    {
        $name = "{$entityClass}::" . Entity::META_SET_SHORT_PATH;

        return defined($name) ? constant($name) : null;
    }

    /**
     * @param class-string<Entity> $entityClass Entity to read
     * @return array<string, string> Its `_foreign` entries, column => referenced table
     */
    private static function foreignOf(string $entityClass): array
    {
        $name = "{$entityClass}::" . Entity::META_FOREIGN;

        return defined($name) ? constant($name) : [];
    }

    /**
     * Maps each mounted table to the collection it is mounted under and its Entity.
     *
     * The same two steps {@see SetOwnershipGuard} takes from a collection to its table: the
     * collection names its Object class, the Object names its Entity. A collection that answers
     * neither is passed over.
     *
     * @return array<string, array{0: string, 1: class-string<Entity>}> Collection key and Entity per table name
     */
    private static function mountedByTable(): array
    {
        $mounted = [];
        foreach (Hilos::$db?->getObjectCollectionClasses() ?? [] as $collectionClass) {
            $objectClass = $collectionClass::OBJECT_CLASS;
            if (!is_subclass_of($objectClass, Object_::class)) {
                continue;
            }

            $entityClass = $objectClass::ENTITY_CLASS;
            if (!is_subclass_of($entityClass, Entity::class) || !defined("{$entityClass}::" . Entity::META_TABLE)) {
                continue;
            }

            $mounted[constant("{$entityClass}::" . Entity::META_TABLE)] = [$collectionClass::COLLECTION_KEY, $entityClass];
        }

        return $mounted;
    }
}
