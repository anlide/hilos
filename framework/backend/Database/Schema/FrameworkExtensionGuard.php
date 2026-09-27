<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Backup\Anonymization\AnonymizationStartupGuard;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Core\Daemon\DaemonApplication;
use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\IncompleteFrameworkExtensionException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Hilos;

/**
 * FrameworkExtensionGuard - the question "is the chain under this framework key whole" asked at the startup of a node.
 *
 * A project extends a framework table by subclassing its whole chain and mounting it under the
 * framework's key through {@see HilosDbContext::frameworkExtensions()} (inheritance.md). The
 * substitution point judges the declaration - that each class it names extends the framework's
 * of the same layer - and nothing more; what the chain came to once mounted is this guard's
 * question, and it is asked of the RESULT rather than of the declaration: the framework's own
 * registration under each key ({@see HilosDbContext::frameworkRegistrations()}) is held against
 * what the context mounted there. A key is extended when the view mounted under it is not the
 * framework's. For such a key every layer of the chain has to be a strict subclass of the
 * framework's class of that layer, read link by link off the constants that couple the layers;
 * a layer still the framework's is refused whether it was never subclassed or the constant was
 * never re-pointed, because without Reflection the two look the same and the cure is the same
 * two steps. Then the two constants naming the Entity have to agree, the subclass has to keep
 * every declaration of the base it stands on - table, primary key, set, every column, type,
 * foreign key, index and verdict, the collection key - no column may carry two verdicts, and
 * every column it added has to carry one, in every project, backup or not. Over every
 * collection mounted, and not only the extended keys, one table may be mounted by one chain.
 * And a framework key whose
 * object collection is not the one its view names has been written over past the substitution
 * point, extended or not.
 *
 * All of it is constants and the mounted map: no query, no Reflection. The refusal is
 * unconditional and at STARTUP, not at the read: a project column that never reached its
 * Object stays silent until the day somebody opens it. Every finding is collected before the
 * throw, because the reader is the author of the chain and one edit answers all of them; a
 * layer that failed costs the checks that would have read through it and never the checks
 * beside it, so one finding does not hide another.
 *
 * Three things it deliberately stays silent about:
 * - A process with no database context, and a framework key nobody extended: the framework's
 *   own chain is not judged against itself.
 * - A key under which no view or no object collection is mounted: that is a broken mount, and
 *   the substitution point answers for it.
 * - A column the project's migration added to a framework table without mapping it in the
 *   subclass's `_columns`: only a live schema knows about it, and
 *   {@see AnonymizationStartupGuard} judges it where a project takes backups.
 *
 * Runs from {@see DaemonApplication::run()}, first of the startup guards: the set-ownership
 * guard, the anonymization registry and the schema audit all read the MOUNTED Entity, and over
 * a half-extended chain they would judge the framework's class in the project's place and say
 * nothing. Only the daemon carries it, for the reason the anonymization guard states about the
 * same set of processes; each demo asks the same question in its topology unit test, over its
 * own context and without a database.
 */
final class FrameworkExtensionGuard
{
    private const string REFUSAL = 'This node refuses to start over a half-extended framework entity: ';

    /** The five layers behind the three declared ones, as a finding names them. */
    private const string LAYER_VIEW_ITEM = 'view item';
    private const string LAYER_OBJECT_COLLECTION = 'object collection';
    private const string LAYER_OBJECT = 'object';
    private const string LAYER_ENTITY_COLLECTION = 'entity collection';
    private const string LAYER_ENTITY = 'entity';

    /** The declarations of the base a subclass keeps as they are: one scalar, or one strategy, each. */
    private const array KEPT_SCALARS = [
        Entity::META_TABLE,
        Entity::META_PRIMARY,
        Entity::META_SET_VIA,
        Entity::META_SET_ROOT,
        Entity::META_SET_SHORT_PATH,
    ];

    /** The lists of the base a subclass keeps every element of. */
    private const array KEPT_LISTS = [Entity::META_COLUMNS, Entity::META_PII_NOT_PERSONAL];

    /**
     * The maps of the base a subclass keeps every key of, with the base's value. The verdict is
     * among them: a map on a classified table, and a strategy kept whole on a purged one.
     */
    private const array KEPT_MAPS = [Entity::META_TYPES, Entity::META_FOREIGN, Entity::META_INDEXES, Entity::META_PII];

    /**
     * Refuses the startup of a node whose chain under a framework key is not whole.
     *
     * Silent over a process with no database context and over an installation that extended
     * no framework key: the framework's own chains are not judged against themselves.
     *
     * @throws IncompleteFrameworkExtensionException When a layer of an extended key is still the
     *     framework's class, the two constants naming the Entity disagree, a framework key is
     *     written over past the substitution point, the subclass does not keep a declaration of
     *     the base, a column carries two verdicts or an added one none, or one table is mounted by two chains
     */
    public static function assertMountedExtensionsWhole(): void
    {
        $db = Hilos::$db;
        if ($db === null) {
            return;
        }

        $problems = [];
        foreach ($db->frameworkRegistrations() as $key => $framework) {
            array_push($problems, ...self::problemsOfKey($db, $key, $framework));
        }
        array_push($problems, ...self::sharedTableProblems($db));

        if ($problems !== []) {
            throw new IncompleteFrameworkExtensionException(self::REFUSAL . implode('; ', $problems));
        }
    }

    /**
     * Judges what is mounted under one framework key against the framework's own chain for it.
     *
     * Every key is asked whether its object collection is the one its view names; the layers are
     * walked only for a key a project extended, which is one whose mounted view is not the
     * framework's.
     *
     * @param HilosDbContext $db Context whose mounts are judged
     * @param string $key Framework collection key
     * @param FrameworkExtension $framework The framework's own chain under the key
     * @return list<string> Findings about the key, empty when the chain under it is whole or the framework's own
     */
    private static function problemsOfKey(HilosDbContext $db, string $key, FrameworkExtension $framework): array
    {
        $view = $db->getDbItemCollection($key);
        $objects = $db->mountedObjectCollection($key);
        if ($view === null || $objects === null) {
            return [];
        }

        $problems = [];
        if ($objects::class !== $view::OBJECT_COLLECTION_CLASS) {
            $problems[] = "key [{$key}]: the mounted object collection " . $objects::class . ' is not ' . $view::class
                . '::OBJECT_COLLECTION_CLASS ' . $view::OBJECT_COLLECTION_CLASS
                . '; a framework key is extended through HilosDbContext::frameworkExtensions(), not written over';
        }

        if ($view::class === $framework->collection) {
            return $problems;
        }

        array_push($problems, ...self::chainProblems($key, $view, $framework));

        return $problems;
    }

    /**
     * Walks the eight layers of an extended key, naming each that is still the framework's.
     *
     * The three mounted classes are judged as mounted; the five behind them are read off the link
     * constants, each against what the framework's class of the layer above names. A link that
     * failed costs the layers reached through it and nothing beside it.
     *
     * @param string $key Framework collection key
     * @param DbCollection $view The view mounted under the key, which is not the framework's
     * @param FrameworkExtension $framework The framework's own chain under the key
     * @return list<string> Findings about the chain, empty when it is whole
     */
    private static function chainProblems(string $key, DbCollection $view, FrameworkExtension $framework): array
    {
        $viewProblem = self::mountedLayerProblem($key, HilosDbContext::LAYER_COLLECTION, $view::class, $framework->collection);
        if ($viewProblem !== null) {
            return [$viewProblem];
        }

        $base = $framework->collection;
        $objectCollectionProblem = self::linkProblem(
            $key,
            $view::class,
            'OBJECT_COLLECTION_CLASS',
            self::LAYER_OBJECT_COLLECTION,
            $base::OBJECT_COLLECTION_CLASS,
        );
        $layerProblems = [
            $framework->actions === null
                ? null
                : self::mountedLayerProblem($key, HilosDbContext::LAYER_ACTIONS, $view->getActionsClass(), $framework->actions),
            $framework->itemActions === null
                ? null
                : self::mountedLayerProblem(
                    $key,
                    HilosDbContext::LAYER_ITEM_ACTIONS,
                    $view->getItemActionsClass(),
                    $framework->itemActions,
                ),
            self::linkProblem($key, $view::class, 'DB_ITEM_CLASS', self::LAYER_VIEW_ITEM, $base::DB_ITEM_CLASS),
            $objectCollectionProblem,
        ];

        $problems = [];
        foreach ($layerProblems as $problem) {
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        if ($objectCollectionProblem === null) {
            array_push(
                $problems,
                ...self::objectCollectionProblems($key, $view::OBJECT_COLLECTION_CLASS, $base::OBJECT_COLLECTION_CLASS),
            );
        }

        return $problems;
    }

    /**
     * Judges the chain from the object collection down: object, entity collection, Entity, the
     * agreement of the two constants naming the Entity, the collection key, and over the Entity the
     * base's declaration and the new columns.
     *
     * @param string $key Framework collection key
     * @param class-string $objects The project's object collection, a strict subclass of the framework's
     * @param class-string $frameworkObjects The framework's object collection for the key
     * @return list<string> Findings from the object collection down, empty when that part of the chain is whole
     */
    private static function objectCollectionProblems(string $key, string $objects, string $frameworkObjects): array
    {
        $objectClass = $objects::OBJECT_CLASS;
        $frameworkObjectClass = $frameworkObjects::OBJECT_CLASS;
        $entityCollectionClass = $objects::ENTITY_COLLECTION_CLASS;

        $objectProblem = self::linkProblem($key, $objects, 'OBJECT_CLASS', self::LAYER_OBJECT, $frameworkObjectClass);
        $entityCollectionProblem = self::linkProblem(
            $key,
            $objects,
            'ENTITY_COLLECTION_CLASS',
            self::LAYER_ENTITY_COLLECTION,
            $frameworkObjects::ENTITY_COLLECTION_CLASS,
        );
        $entityProblem = $objectProblem === null
            ? self::linkProblem($key, $objectClass, 'ENTITY_CLASS', self::LAYER_ENTITY, $frameworkObjectClass::ENTITY_CLASS)
            : null;

        $problems = [];
        foreach ([$objectProblem, $entityCollectionProblem, $entityProblem] as $problem) {
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if (
            $objectProblem === null
            && $entityCollectionProblem === null
            && $entityCollectionClass::ENTITY_CLASS !== $objectClass::ENTITY_CLASS
        ) {
            $problems[] = "key [{$key}]: {$entityCollectionClass}::ENTITY_CLASS names " . $entityCollectionClass::ENTITY_CLASS
                . " while {$objectClass}::ENTITY_CLASS names " . $objectClass::ENTITY_CLASS
                . '; both name the one Entity of the chain';
        }

        if ($objects::COLLECTION_KEY !== $frameworkObjects::COLLECTION_KEY) {
            $problems[] = self::notKeptProblem($objects, 'COLLECTION_KEY', $frameworkObjects);
        }

        if ($objectProblem === null && $entityProblem === null) {
            array_push($problems, ...self::declarationProblems($objectClass::ENTITY_CLASS, $frameworkObjectClass::ENTITY_CLASS));
            array_push($problems, ...self::newColumnProblems($objectClass::ENTITY_CLASS, $frameworkObjectClass::ENTITY_CLASS));
        }

        return $problems;
    }

    /**
     * Judges one of the three mounted layers: the class the context mounted for it has to be a
     * strict subclass of the framework's.
     *
     * @param string $key Framework collection key
     * @param string $layer Name of the layer, for the finding
     * @param ?string $class The class mounted for the layer, or null where none is - shown as an empty name
     * @param class-string $frameworkClass The framework's class for the layer
     * @return ?string The finding, or null when the mounted class is a strict subclass
     */
    private static function mountedLayerProblem(string $key, string $layer, ?string $class, string $frameworkClass): ?string
    {
        if ($class !== null && is_subclass_of($class, $frameworkClass)) {
            return null;
        }

        return "key [{$key}]: the mounted {$layer} {$class} does not extend the framework's {$frameworkClass};"
            . ' extend it and declare it in HilosDbContext::frameworkExtensions()';
    }

    /**
     * Judges one link of the chain: the class a constant of the project's class names has to be a
     * strict subclass of what the framework's class of the same layer names there.
     *
     * @param string $key Framework collection key
     * @param class-string $holder The project's class carrying the link constant
     * @param string $constant Name of the link constant
     * @param string $layer Name of the layer the constant names, for the finding
     * @param class-string $frameworkClass What the framework's chain has at that layer
     * @return ?string The finding, or null when the link names a strict subclass
     */
    private static function linkProblem(string $key, string $holder, string $constant, string $layer, string $frameworkClass): ?string
    {
        $named = constant("{$holder}::{$constant}");
        if (is_subclass_of($named, $frameworkClass)) {
            return null;
        }

        return "key [{$key}]: {$holder}::{$constant} names {$named} as the {$layer}, which does not extend"
            . " the framework's {$frameworkClass}; extend it and point {$constant} at the subclass";
    }

    /**
     * Judges the Entity's declaration against the base's, constant by constant: scalars and the
     * purge strategy kept as they are, lists with every element of the base's, maps with every
     * key of the base's and the same value under it. Only constants the base declares are judged.
     *
     * @param class-string<Entity> $entity The project's Entity
     * @param class-string<Entity> $base The framework's Entity it extends
     * @return list<string> Findings about declarations not kept, empty when every one is
     */
    private static function declarationProblems(string $entity, string $base): array
    {
        $problems = [];
        foreach (self::KEPT_SCALARS as $constant) {
            if (defined("{$base}::{$constant}") && constant("{$entity}::{$constant}") !== constant("{$base}::{$constant}")) {
                $problems[] = self::notKeptProblem($entity, $constant, $base);
            }
        }

        foreach (self::KEPT_LISTS as $constant) {
            if (!defined("{$base}::{$constant}")) {
                continue;
            }

            $mine = constant("{$entity}::{$constant}");
            $lost = array_diff(constant("{$base}::{$constant}"), is_array($mine) ? $mine : []);
            if ($lost !== []) {
                $problems[] = self::notKeptWholeProblem($entity, $constant, $base, array_values($lost));
            }
        }

        foreach (self::KEPT_MAPS as $constant) {
            if (!defined("{$base}::{$constant}")) {
                continue;
            }

            $theirs = constant("{$base}::{$constant}");
            $mine = constant("{$entity}::{$constant}");
            if (!is_array($theirs)) {
                if ($mine !== $theirs) {
                    $problems[] = self::notKeptProblem($entity, $constant, $base);
                }

                continue;
            }

            $lost = [];
            foreach ($theirs as $name => $value) {
                if (!is_array($mine) || !array_key_exists($name, $mine) || $mine[$name] !== $value) {
                    $lost[] = $name;
                }
            }
            if ($lost !== []) {
                $problems[] = self::notKeptWholeProblem($entity, $constant, $base, $lost);
            }
        }

        return $problems;
    }

    /**
     * Judges the Entity's verdict the two ways the framework judges its own tables': no column is
     * named both personal and not personal, asked over the whole verdict - a base column the
     * subclass adds to `_pii` while the inherited `_piiNotPersonal` still lists it is the same
     * double verdict as one on a column of its own - and every column the Entity added to the
     * base's is named in one of the two. A purged table covers its columns whole and is not asked.
     *
     * Asked in every project: a column of a framework table is classified whether or not the
     * installation takes backups, because the verdict has readers past the backup.
     *
     * @param class-string<Entity> $entity The project's Entity
     * @param class-string<Entity> $base The framework's Entity it extends
     * @return list<string> Findings about the verdict, empty when every column carries exactly one
     */
    private static function newColumnProblems(string $entity, string $base): array
    {
        $verdict = defined("{$entity}::" . Entity::META_PII) ? constant("{$entity}::" . Entity::META_PII) : [];
        if ($verdict instanceof AnonymizationStrategy) {
            return [];
        }

        $personal = is_array($verdict) ? array_keys($verdict) : [];
        $notPersonal = defined("{$entity}::" . Entity::META_PII_NOT_PERSONAL)
            ? constant("{$entity}::" . Entity::META_PII_NOT_PERSONAL)
            : [];

        $problems = [];
        foreach (array_intersect($personal, $notPersonal) as $column) {
            $problems[] = "{$entity} names its column [{$column}] both personal and not personal";
        }

        $columns = defined("{$entity}::" . Entity::META_COLUMNS) ? constant("{$entity}::" . Entity::META_COLUMNS) : [];
        $baseColumns = defined("{$base}::" . Entity::META_COLUMNS) ? constant("{$base}::" . Entity::META_COLUMNS) : [];
        foreach (array_diff($columns, $baseColumns) as $column) {
            if (!in_array($column, $personal, true) && !in_array($column, $notPersonal, true)) {
                $problems[] = "{$entity} says nothing about its column [{$column}], which is neither in "
                    . Entity::META_PII . ' nor in ' . Entity::META_PII_NOT_PERSONAL;
            }
        }

        return $problems;
    }

    /**
     * Names every table that more than one mounted collection resolves to, over every collection
     * the context mounted and not only the framework's keys.
     *
     * The two steps from a collection to its table are the sync applicator's: the collection names
     * its Object, the Object its Entity. A collection that answers neither is passed over - a broken
     * mount, not a second chain.
     *
     * @param HilosDbContext $db Context whose mounts are judged
     * @return list<string> One finding per table under two or more chains, empty when every table has one
     */
    private static function sharedTableProblems(HilosDbContext $db): array
    {
        $collectionsByTable = [];
        foreach ($db->getObjectCollectionClasses() as $collectionClass) {
            $objectClass = $collectionClass::OBJECT_CLASS;
            if (!is_subclass_of($objectClass, Object_::class)) {
                continue;
            }

            $entityClass = $objectClass::ENTITY_CLASS;
            if (!is_subclass_of($entityClass, Entity::class) || !defined("{$entityClass}::" . Entity::META_TABLE)) {
                continue;
            }

            $collectionsByTable[constant("{$entityClass}::" . Entity::META_TABLE)][] = $collectionClass;
        }

        $problems = [];
        foreach ($collectionsByTable as $table => $collections) {
            if (count($collections) < 2) {
                continue;
            }

            $problems[] = "table {$table} is mounted by " . implode(' and ', array_map(
                static fn(string $collection): string => "{$collection} [" . $collection::COLLECTION_KEY . ']',
                $collections,
            )) . '; one table, one mounted chain - a framework table is extended under its own key through'
                . ' HilosDbContext::frameworkExtensions()';
        }

        return $problems;
    }

    /**
     * @param class-string $class The project's class
     * @param string $constant Name of the constant not kept
     * @param class-string $frameworkClass The framework's class whose value it had to keep
     * @return string The finding
     */
    private static function notKeptProblem(string $class, string $constant, string $frameworkClass): string
    {
        return "{$class}::{$constant} does not keep the framework's {$frameworkClass}::{$constant}";
    }

    /**
     * @param class-string $class The project's class
     * @param string $constant Name of the list or map not kept whole
     * @param class-string $frameworkClass The framework's class whose elements it had to keep
     * @param list<int|string> $lost Elements or keys of the base's value missing or changed in the project's
     * @return string The finding
     */
    private static function notKeptWholeProblem(string $class, string $constant, string $frameworkClass, array $lost): string
    {
        return self::notKeptProblem($class, $constant, $frameworkClass) . ': lost or changed [' . implode(', ', $lost)
            . "]; compose it from parent::{$constant}";
    }
}
