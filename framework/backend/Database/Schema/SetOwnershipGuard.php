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
 * whether other tables may hang their sets off this one. Both are constants, so the whole
 * question is answered without a single query - what this reads is the map of collections a
 * {@see DbContext} mounted, not a schema.
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
 * - A child naming a column that carries no `_foreign` entry gets no cross-check. Framework
 *   Entities have none on purpose - `user_id` is a soft reference without a foreign key across
 *   the framework/project boundary ({@see Notification}) - so the set of `hilos_identity` on a
 *   project's own `user` is a named hole, not an oversight.
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
     *     column it does not have, declares a non-boolean root, or hangs its set on a table that
     *     does not declare itself a root
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
}
