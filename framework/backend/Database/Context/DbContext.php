<?php

declare(strict_types=1);

namespace Hilos\Database\Context;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\View\CloneNotAllowedException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Exception\View\UnknownLazyStrategyException;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\HilosException;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Logger;

/**
 * DbContext - Database context (instance layer only).
 * Contains object collections and their DB collection wrappers.
 *
 * @template T of DbContext
 */
abstract class DbContext
{
    /**
     * Object collections (references to Objects instances)
     * These are set by setRepresent() method.
     *
     * @var array<string, Objects>
     */
    protected array $_objectCollections = [];

    /**
     * DB item collections (wrappers around Object collections)
     * These are created automatically from Object collections.
     *
     * @var array<string, DbCollection>
     */
    protected array $_dbItemCollections = [];

    /**
     * Baseline DB generation marker, sampled at init() and after each re-hydration.
     * Null until refreshDbGeneration() first reads it (or when the marker is
     * unavailable, e.g. no DB connection). A change signals the DB was replaced
     * under the live process (external db-reset or restore).
     *
     * @var ?string
     */
    protected ?string $_dbGeneration = null;

    /**
     * Creates DB context.
     *
     * Called from facade createDb().
     */
    public function __construct()
    {
    }

    /**
     * Public clone - prevent cloning.
     *
     * @throws CloneNotAllowedException Always, cloning not allowed
     */
    public function __clone(): void
    {
        throw new CloneNotAllowedException('DbContext cannot be cloned');
    }

    /**
     * Set representation for object collection.
     *
     * @param string $name Collection name (e.g. users)
     * @param class-string<DbCollection> $dbItemCollectionClass DB collection class name
     * @param ?class-string<DbActions> $actionsClass Collection actions class name (optional)
     * @param ?class-string<TableItemActions> $itemActionsClass Item actions class name (optional)
     * @throws ObjectCollectionNotFoundException When object collection not found
     */
    public function setRepresent(string $name, string $dbItemCollectionClass, ?string $actionsClass = null, ?string $itemActionsClass = null): void
    {
        $objectCollection = $this->_objectCollections[$name]
            ?? throw new ObjectCollectionNotFoundException(
                "Object collection [{$name}] not found in _objectCollections. Create it before calling setRepresent()."
            );

        if (is_subclass_of($dbItemCollectionClass, DbCollection::class)) {
            $dbItemCollection = $dbItemCollectionClass::init();
            $dbItemCollection->setObjectCollection($objectCollection);
            if ($actionsClass !== null) {
                $dbItemCollection->setActionsClass($actionsClass);
            }
            if ($itemActionsClass !== null) {
                $dbItemCollection->setItemActionsClass($itemActionsClass);
            }
            $this->_dbItemCollections[$name] = $dbItemCollection;
        }
    }

    /**
     * Get object collection by name, refusing one this process does not read.
     *
     * The application's way into the object layer, and judged like the View layer is
     * ({@see self::__get()}): a read past the agent is a read all the same, and answering it out
     * of a copy the master never updates would be worse than refusing it, because the rows come
     * back and are wrong only later. The mount is answered before the interest, so a name nothing
     * mounted still answers null - a caller asking about a feature this installation did not
     * activate must not be told its interest is missing.
     *
     * The layer's own machinery goes through {@see self::mountedObjectCollection()} instead.
     *
     * @param string $name Collection name (e.g. users, events)
     * @return ?Objects Object collection or null if not found
     * @throws DbCollectionNotReadableException When nothing here reads the collection, or its readiness is on its way
     */
    public function getObjectCollection(string $name): ?Objects
    {
        if (!isset($this->_objectCollections[$name])) {
            return null;
        }

        $this->assertReadable($name);

        return $this->_objectCollections[$name];
    }

    /**
     * Get object collection by name as it is mounted, without asking whether it is readable.
     *
     * For the two callers that are the layer itself rather than a reader of rows: DbSyncApplicator
     * applying an incoming row change, and TopologyValidator asking whether a collection is
     * mounted at all. Delivery cannot be judged by the guard it feeds - it is what makes a
     * collection readable in the first place, so a judged delivery would refuse to deliver the
     * very state it was refused for lacking.
     *
     * Told apart by the name and not by the caller: reading the stack or the call site is out by
     * the style rules, and a reader reaching for the familiar name lands on the judged entrance.
     *
     * @param string $name Collection name (e.g. users, events)
     * @return ?Objects Object collection or null if nothing is mounted under that name
     */
    public function mountedObjectCollection(string $name): ?Objects
    {
        return $this->_objectCollections[$name] ?? null;
    }

    /**
     * Names the object collection classes this context mounted, in registration order.
     *
     * The list a backup restore reads every table's personal-data verdict out of: a
     * collection is where an installation says a table of its is part of the system, so
     * walking them needs no second hand-written list of tables to fall behind the first.
     * Order is registration order, which the anonymization pass then executes in.
     *
     * @return list<class-string<Objects>> Object collection classes, in registration order
     */
    public function getObjectCollectionClasses(): array
    {
        return array_values(array_map(
            static fn(Objects $collection): string => $collection::class,
            $this->_objectCollections,
        ));
    }

    /**
     * Get DB item view collection by name.
     *
     * The registry of views has no way in from outside otherwise: {@see self::__get()} loads the
     * collection from the database when it is missing, and {@see self::getObjectCollection()}
     * hands out the store rather than the view. What a subscriber repairing a view cache needs
     * is the view already mounted under that name, and nothing else.
     *
     * @param string $name Collection name (e.g. users, events)
     * @return ?DbCollection DB item view collection, or null when no view is mounted under that name
     */
    public function getDbItemCollection(string $name): ?DbCollection
    {
        return $this->_dbItemCollections[$name] ?? null;
    }

    /**
     * Re-reads one collection from the database and drops its cached DbItems.
     *
     * Used by DB_SYNC_CLEARED apply to follow a remote deleteAll() truncate. The
     * physical DELETE already ran in the originating process, so after a legitimate
     * clear this re-read returns nothing and costs one empty SELECT. It is a re-read
     * rather than a blind blanking so that applying the same clear twice converges on
     * whatever the table now holds instead of leaving the mirror stuck empty over rows
     * written after the truncate.
     *
     * @param string $name Collection name (e.g. users, events)
     * @throws LogicException When the collection entity class is not configured (eager reload)
     * @throws DatabaseException If reloading an eager collection from the database fails
     */
    public function reHydrateCollection(string $name): void
    {
        ($this->_objectCollections[$name] ?? null)?->reHydrate();
        ($this->_dbItemCollections[$name] ?? null)?->clearCache();
    }

    /**
     * Re-hydrates every DB-backed collection from the current DB.
     *
     * Used when the DB was replaced under the live process (external db-reset or
     * restore): each object collection is reset to its fresh post-initDB state
     * (eager collections reload now, lazy ones on next access) and each DbItem
     * wrapper cache is dropped so it does not return stale items. Does not touch
     * the DB generation baseline; callers that detected a change refresh it.
     *
     * @throws LogicException When a represented collection entity class is not configured (eager reload)
     * @throws DatabaseException If reloading an eager collection from the fresh DB fails
     * @throws HilosException When the concrete collection refuses to be loaded directly
     */
    public function reHydrateDbBackedCollections(): void
    {
        foreach ($this->_objectCollections as $name => $objectCollection) {
            $objectCollection->reHydrate();
            ($this->_dbItemCollections[$name] ?? null)?->clearCache();
        }
    }

    /**
     * Samples the current DB generation as the baseline. Called from Hilos::init()
     * after configure() so a later reHydrateIfDbChanged() can detect a replacement.
     */
    public function refreshDbGeneration(): void
    {
        $this->_dbGeneration = $this->readDbGeneration();
    }

    /**
     * Re-hydrates all DB-backed collections when the DB generation changed since the
     * baseline, the fallback for a raw db-reset that fires no restore signal.
     *
     * On a detected change it re-hydrates and refreshes the baseline, so a stale
     * in-memory row that collided with a freshly-minted id is replaced rather than
     * rejected. Returns false when the generation is unchanged or unreadable, so a
     * genuine duplicate id still surfaces as an error.
     *
     * @return bool True when the DB changed and collections were re-hydrated
     * @throws LogicException When a represented collection entity class is not configured (eager reload)
     * @throws DatabaseException If reloading an eager collection from the fresh DB fails
     * @throws HilosException When the concrete collection refuses to be loaded directly
     */
    public function reHydrateIfDbChanged(): bool
    {
        $current = $this->readDbGeneration();
        if ($current === null || $current === $this->_dbGeneration) {
            return false;
        }

        $this->reHydrateDbBackedCollections();
        $this->_dbGeneration = $current;

        return true;
    }

    /**
     * Reads a marker that changes when the DB schema is dropped and recreated.
     *
     * Default reads the max table CREATE_TIME plus table count from
     * information_schema, which jumps on the DROP+CREATE of a db-reset or restore.
     * Returns null when the marker cannot be read (e.g. no DB connection), which
     * callers treat as "unchanged". Overridable so unit tests can drive the marker
     * without a real DB.
     *
     * @return ?string DB generation marker, or null when unavailable
     */
    protected function readDbGeneration(): ?string
    {
        try {
            $row = Database::sql(
                "SELECT MAX(`CREATE_TIME`) AS `create_time`, COUNT(*) AS `table_count` "
                . "FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()"
            )->first()?->first();
        } catch (DatabaseException) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        // external-boundary: information_schema leaves both cells empty for a database with no tables
        return (string)($row['create_time'] ?? '') . ':' . (string)($row['table_count'] ?? '');
    }

    /**
     * Get DB collection by name (magic getter for $db->users, $db->events, etc.).
     *
     * @param string $name Collection name (e.g. users, events)
     * @return DbCollection DB collection instance
     * @throws CollectionNotFoundException When collection does not exist
     * @throws DbCollectionNotReadableException When nothing here reads the collection, or its readiness is on its way
     * @throws UnknownLazyStrategyException When lazy strategy is unknown
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException On connection or schema error
     */
    public function __get(string $name)
    {
        if (!isset($this->_dbItemCollections[$name])) {
            throw new CollectionNotFoundException("Db collection [{$name}] does not exist");
        }

        $this->assertReadable($name);

        switch ($this->_objectCollections[$name]->getLazyStrategy()) {
            case Objects::LAZY_STRATEGY_NONE:
                if (!$this->_objectCollections[$name]->isAllLoaded()) {
                    $this->_objectCollections[$name]->loadAllFromDB();
                }
                break;

            case Objects::LAZY_STRATEGY_KEY:
                break;

            case Objects::LAZY_STRATEGY_BATCH:
                break;

            case Objects::LAZY_STRATEGY_FULL_ON_ACCESS:
                break;

            default:
                throw new UnknownLazyStrategyException("Unknown lazy loading strategy for collection [{$name}]");
        }

        return $this->_dbItemCollections[$name];
    }

    /**
     * Declares what this process reads because the framework reads it, not because somebody asked.
     *
     * Called once per process right after {@see self::configure()}, and the database twin of
     * {@see RtContext::declareProcessWideReads()}. What it covers is the seams: code that answers
     * a question in whatever process happens to be running - whose session is this, what is this
     * setting - and is therefore named by no page's topology and no agent's
     * {@see AbstractAgent::READS_DB}. Without the declaration those reads would be refused in
     * every worker that runs no page and no agent of its own, which is most of them.
     *
     * A page nobody subscribes to reads the same way and belongs here for the same reason. It has
     * a class and a name, so {@see AbstractPage::READS_DB} looks like the place - but that list is
     * taken up when a connection subscribes, and a page that never is has its actions run
     * wherever the person happens to be instead (HIL-750). The framework has no page of that kind
     * left since HIL-860 retired the notification center's one; the rule is here for the projects
     * that do.
     *
     * The interest is never given back. A seam has no end the way a page subscription or an agent
     * does: it stops being read when the process stops.
     *
     * The list is named rather than derived, because there is nothing in a mounted collection
     * that says who reads it - {@see self::processWideReadCollections()} is where a layer says so.
     */
    final public function declareProcessWideReads(): void
    {
        foreach ($this->processWideReadCollections() as $collectionKey) {
            SourceInterestRegistry::register(
                SourceChange::KIND_DB,
                $collectionKey,
                SourceConsumer::feature($collectionKey),
            );
        }
    }

    /**
     * Names the collections this layer reads from any process at all.
     *
     * Empty here: a bare context mounts nothing of its own, so it reads nothing of its own. The
     * framework's own seams are named by {@see HilosDbContext}, and a project adds to that list
     * by overriding this and calling the parent.
     *
     * @return list<string> Collection keys read process-wide
     */
    protected function processWideReadCollections(): array
    {
        return [];
    }

    /**
     * Refuses a collection this process does not read, rather than answering out of a copy
     * nobody keeps current.
     *
     * The database twin of {@see RtContext::assertReadable()}, and refused for a reason of its
     * own: the rows here WOULD come back, out of the shared database, and they would be right
     * exactly once. A process the master does not address is a process no later write reaches,
     * so its copy stops being true at the next write and says nothing about when that was.
     *
     * Judged in a worker and nowhere else, which is what {@see SourceInterestRegistry::isReady()}
     * answers: every other process holds what it mounted itself and is its own source of it, so
     * a refusal there would be about a delivery that is not happening.
     *
     * Only mounted collections reach here, so the refusal is always about wiring: either no
     * consumer declared this collection ({@see AbstractAgent::READS_DB}, a page's browser
     * sources and {@see AbstractPage::READS_DB}, a framework seam's process-wide read) or one did
     * and the master's confirmation has not landed yet. The two are separate defects and the
     * messages say which.
     *
     * @param string $name Mounted collection name being read
     * @throws DbCollectionNotReadableException When nothing here reads it, or its readiness is on its way
     */
    private function assertReadable(string $name): void
    {
        if (SourceInterestRegistry::isReady(SourceChange::KIND_DB, $name)) {
            return;
        }

        $reason = SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, $name)
            ? "database collection '{$name}' was declared but its readiness has not arrived yet"
            : "no reader interest is registered for database collection '{$name}'";

        // Written here and not left to whoever catches it: a refusal is a wiring defect, and the
        // caller closest to it is the one most likely to answer it with a fallback. Both messages
        // reach the journal, because which of the two it was is the whole diagnosis.
        Logger::error("Database read refused: {$reason}");

        throw new DbCollectionNotReadableException($reason);
    }

    /**
     * Configure collections (register object collections and setRepresent).
     *
     * Called from facade init() after createDb() and createRuntime().
     *
     * @throws HilosException When registering the project's collections fails
     */
    abstract public function configure(): void;

    /**
     * Convert all collections to array.
     *
     * @return array<string, array<int|string, array<string, mixed>>> Collection name => items array
     * @throws LogicException When a represented collection class is misconfigured
     * @throws InvalidArgumentException When an object type does not match its collection
     */
    public function toArray(): array
    {
        return array_map(function ($collection) {
            return $collection->toArray();
        }, $this->_dbItemCollections);
    }
}
