<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Core\Daemon\DaemonApplication;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Notification;
use Hilos\Database\Exception\UndeclaredSetOwnershipException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos;

/**
 * SetOwnershipGuard - the question "whose set is this table part of" asked at the startup of a node.
 *
 * Every mounted table owes two declarations: `_setVia` names the column its set is cut by, or
 * says {@see Entity::SET_STANDALONE} when its rows belong to nobody's set, and `_setRoot` says
 * whether other tables may hang their sets off this one. A third is optional - `_setShortPath`
 * names another column that carries the key at the top of the set tree directly - and is judged
 * when present. The parents a set column points at are followed too, and a chain of them that
 * returns to the table it started from is refused: the climb to the top of the tree
 * ({@see SetTree}) would never end. All of it is constants, so the whole question is answered
 * without a single query - what this reads is the map of collections a {@see DbContext}
 * mounted, not a schema.
 *
 * The refusal is unconditional, unlike the coverage gate of anonymization it is modelled on:
 * there is no feature to hide behind, because every installation reads sets. It refuses the
 * STARTUP rather than the operation that wanted the set, because the gap is born on the day of
 * a migration and a refused operation would surface it on the day somebody opened a page -
 * which is exactly the failure HIL-781 was written about.
 *
 * All findings are collected before the throw rather than reported one per start: the
 * declarations are written by hand, and an author fixing a first complaint only to meet a
 * second one is how a gate acquires a reputation for being in the way. The reader of a refusal
 * is the author of an Entity or of a migration, not the operator who started the node.
 *
 * Two things it deliberately stays silent about:
 * - A child naming a column that carries no `_foreign` entry gets no cross-check, and its set
 *   is not climbed: the value is the top. Framework Entities hang their sets on the person that
 *   way - `user_id` is a soft reference while the person table belongs to the project
 *   ({@see Notification}) - so the set of `hilos_identity` on a project's own `user` is a named
 *   hole, not an oversight.
 * - A mounted collection that resolves to no Entity is passed over. That is a broken mount
 *   rather than an undeclared set, and this gate answers one question only.
 *
 * Runs from {@see DaemonApplication::run()}, ahead of the anonymization coverage gate: this one
 * reads constants alone and so costs less, and an unmarked table is the more basic defect of
 * the two. Only the daemon carries it, for the reason the anonymization guard states about the
 * same set of processes.
 */
final class SetOwnershipGuard
{
    /**
     * Refuses the startup of a node whose mounted tables do not declare whose set they are part of.
     *
     * Silent over an installation that mounted nothing: a context with no collections declares
     * no sets and has none to answer for.
     *
     * @throws UndeclaredSetOwnershipException When a mounted table declares no ownership, names a
     *     column it does not have, declares a non-boolean root, hangs its set on a table that does
     *     not declare itself a root, declares a short path that is not another column of a table in
     *     a set, or hangs its set on a chain of parents that returns to itself
     */
    public static function assertMountedSetsDeclared(): void
    {
        $entityClasses = self::mountedEntities();

        $rootByTable = [];
        foreach ($entityClasses as $entityClass) {
            if (defined("{$entityClass}::" . Entity::META_TABLE)) {
                $rootByTable[constant("{$entityClass}::" . Entity::META_TABLE)] = $entityClass;
            }
        }

        $problems = [];
        foreach ($entityClasses as $entityClass) {
            array_push($problems, ...self::problemsOf($entityClass, $rootByTable));
        }
        foreach ($entityClasses as $entityClass) {
            array_push($problems, ...self::cycleOf($entityClass, $rootByTable));
        }

        if ($problems !== []) {
            throw new UndeclaredSetOwnershipException(
                'This node refuses to start over tables that do not say whose set they belong to: '
                . implode('; ', $problems),
            );
        }
    }

    /**
     * Names the Entity classes of the collections this installation mounted.
     *
     * The two steps from a collection to its table class are the ones the sync applicator takes
     * ({@see DbSyncApplicator}): the collection names its Object class, the Object names its
     * Entity. A collection that answers neither is passed over, as the docblock of this class
     * says.
     *
     * @return list<class-string<Entity>> Entity classes of the mounted collections, in registration order
     */
    private static function mountedEntities(): array
    {
        $entityClasses = [];
        foreach (Hilos::$db?->getObjectCollectionClasses() ?? [] as $collectionClass) {
            $objectClass = $collectionClass::OBJECT_CLASS;
            if (!is_subclass_of($objectClass, Object_::class)) {
                continue;
            }

            $entityClass = $objectClass::ENTITY_CLASS;
            if (!is_subclass_of($entityClass, Entity::class)) {
                continue;
            }

            $entityClasses[] = $entityClass;
        }

        return $entityClasses;
    }

    /**
     * Judges one Entity's pair of declarations, naming everything wrong with it.
     *
     * A missing constant costs the checks that would have read it - there is no value to judge -
     * but never the checks on the other constant of the pair, so one silent Entity does not hide
     * behind another's finding.
     *
     * @param class-string<Entity> $entityClass Entity to judge
     * @param array<string, class-string<Entity>> $rootByTable Mounted Entity per table name
     * @return list<string> Findings about this Entity, empty when it declares its set properly
     */
    private static function problemsOf(string $entityClass, array $rootByTable): array
    {
        $problems = [];

        if (!defined("{$entityClass}::" . Entity::META_SET_ROOT)) {
            $problems[] = "{$entityClass} declares no " . Entity::META_SET_ROOT;
        } elseif (!is_bool(constant("{$entityClass}::" . Entity::META_SET_ROOT))) {
            $problems[] = "{$entityClass} declares a non-boolean " . Entity::META_SET_ROOT;
        }

        if (!defined("{$entityClass}::" . Entity::META_SET_VIA)) {
            $problems[] = "{$entityClass} declares no " . Entity::META_SET_VIA;

            return $problems;
        }

        $column = constant("{$entityClass}::" . Entity::META_SET_VIA);
        array_push($problems, ...self::shortPathProblemsOf($entityClass, $column));
        if ($column === Entity::SET_STANDALONE) {
            return $problems;
        }

        $columns = defined("{$entityClass}::" . Entity::META_COLUMNS)
            ? constant("{$entityClass}::" . Entity::META_COLUMNS)
            : [];
        if (!in_array($column, $columns, true)) {
            $problems[] = "{$entityClass} names column '{$column}' in " . Entity::META_SET_VIA
                . ', which is not among its ' . Entity::META_COLUMNS;

            return $problems;
        }

        $foreign = defined("{$entityClass}::" . Entity::META_FOREIGN)
            ? constant("{$entityClass}::" . Entity::META_FOREIGN)
            : [];
        // The cross-check follows the VALUE of the foreign entry and not the name of the column:
        // `event_attachment` calls its column `event_id` and hangs it on `event_message`.
        $ownerTable = $foreign[$column] ?? null;
        if ($ownerTable === null || !isset($rootByTable[$ownerTable])) {
            return $problems;
        }

        $ownerClass = $rootByTable[$ownerTable];
        if (
            !defined("{$ownerClass}::" . Entity::META_SET_ROOT)
            || constant("{$ownerClass}::" . Entity::META_SET_ROOT) !== true
        ) {
            $problems[] = "{$entityClass} hangs its set on {$ownerTable}, which does not declare itself a set root";
        }

        return $problems;
    }

    /**
     * Judges the optional short path of one Entity, naming what is wrong with it.
     *
     * A short path is another column of a table in a set that carries the key at the top of its
     * set tree: a table in nobody's set has no top to carry, and the set column itself is not a
     * path around anything.
     *
     * @param class-string<Entity> $entityClass Entity to judge
     * @param string $setVia Its `_setVia` declaration
     * @return list<string> Findings about the short path, empty when none is declared or it is sound
     */
    private static function shortPathProblemsOf(string $entityClass, string $setVia): array
    {
        if (!defined("{$entityClass}::" . Entity::META_SET_SHORT_PATH)) {
            return [];
        }

        if ($setVia === Entity::SET_STANDALONE) {
            return ["{$entityClass} declares " . Entity::META_SET_SHORT_PATH
                . " on a table that belongs to nobody's set (Entity::SET_STANDALONE)"];
        }

        $shortPath = constant("{$entityClass}::" . Entity::META_SET_SHORT_PATH);
        if (!is_string($shortPath)) {
            return ["{$entityClass} declares a non-string " . Entity::META_SET_SHORT_PATH];
        }

        $columns = defined("{$entityClass}::" . Entity::META_COLUMNS)
            ? constant("{$entityClass}::" . Entity::META_COLUMNS)
            : [];
        if (!in_array($shortPath, $columns, true)) {
            return ["{$entityClass} names column '{$shortPath}' in " . Entity::META_SET_SHORT_PATH
                . ', which is not among its ' . Entity::META_COLUMNS];
        }

        if ($shortPath === $setVia) {
            return ["{$entityClass} names its " . Entity::META_SET_VIA . " column '{$shortPath}' as "
                . Entity::META_SET_SHORT_PATH . ': a short path is another column that carries the top of the set tree'];
        }

        return [];
    }

    /**
     * Follows the parents one Entity's set column points at, naming a chain that returns to it.
     *
     * The parent is the mounted table the `_foreign` entry of the set column names; the chain ends
     * at a soft reference, at a table that is not mounted, and at a table in nobody's set. A chain
     * that runs into a loop further up without coming back here is named by the tables of that
     * loop, each on its own walk, and so is left alone on this one.
     *
     * @param class-string<Entity> $entityClass Entity whose set column starts the chain
     * @param array<string, class-string<Entity>> $rootByTable Mounted Entity per table name
     * @return list<string> The finding about this Entity's chain, empty when it ends
     */
    private static function cycleOf(string $entityClass, array $rootByTable): array
    {
        if (!defined("{$entityClass}::" . Entity::META_TABLE)) {
            return [];
        }

        $tables = [constant("{$entityClass}::" . Entity::META_TABLE)];
        $class = $entityClass;
        while (($column = SetTree::setColumnOf($class)) !== null) {
            $foreign = defined("{$class}::" . Entity::META_FOREIGN) ? constant("{$class}::" . Entity::META_FOREIGN) : [];
            $parentTable = $foreign[$column] ?? null;
            if ($parentTable === null || !isset($rootByTable[$parentTable])) {
                return [];
            }

            if (in_array($parentTable, $tables, true)) {
                return $parentTable === $tables[0]
                    ? ["{$entityClass} hangs its set on a chain that returns to itself: "
                        . implode(' -> ', [...$tables, $parentTable])]
                    : [];
            }

            $tables[] = $parentTable;
            $class = $rootByTable[$parentTable];
        }

        return [];
    }
}
