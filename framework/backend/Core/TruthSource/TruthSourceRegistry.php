<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Closure;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;

/**
 * Database Truth Source Registry.
 *
 * Tracks which agents are sources of truth for specific database tables.
 * Only registered agents can write to database tables.
 *
 * Create permission is not a second mechanism: it is a grant that covers no rows and allows
 * only {@see TruthSourceOperation::Add}. Register via registerCreate() for agents that mint
 * new records without owning any.
 *
 * Usage:
 *   // In Agent::onStart()
 *   TruthSourceRegistry::register(Hilos::users, TruthSourceKeys::all(), $this->getId());
 *   TruthSourceRegistry::registerCreate(Hilos::bots, $this->getId());
 *
 *   // After Agent::onStop() returns or throws, WorkerManager unregisters the agent.
 *   TruthSourceRegistry::unregisterAgent($agent->getId());
 *
 *   // In DbActions (automatic check)
 *   TruthSourceRegistry::checkCanWrite($tableName, $operation);
 *   TruthSourceRegistry::checkCanWriteSet($tableName, $setKey, $topSetKeys, $operation);
 *   TruthSourceRegistry::checkCanCreate($tableName);
 */
class TruthSourceRegistry extends AbstractTruthSourceRegistry
{
    /** @var array<string, array<string, TruthSourceGrant>> [table => [agentId => grant]] */
    private static array $sources = [];

    /**
     * Get sources storage reference.
     *
     * @return array<string, array<string, TruthSourceGrant>> Reference to table→agentId→grant mapping
     */
    protected static function &getSources(): array
    {
        return self::$sources;
    }

    /**
     * Register agent as having create permission for collection
     *
     * On its own the grant covers no rows, and that is deliberate: minting a record is not
     * owning one, and an empty width keeps
     * {@see AbstractTruthSourceRegistry::getTruthSourceKeys()} answering as it did before the
     * create right moved onto the operation axis. An agent that already holds a grant here
     * keeps it and gains the create operation - the two rights used to live in two stores and
     * could be claimed in either order, and folding them onto one axis must not turn the
     * second call into a revocation of the first.
     *
     * @param string $collection Collection/table name
     * @param string $agentId Agent ID from agent->getId()
     */
    public static function registerCreate(string $collection, string $agentId): void
    {
        $grant = self::grantOf($collection, $agentId);
        if ($grant === null) {
            self::register(
                $collection,
                TruthSourceKeys::listed(),
                $agentId,
                TruthSourceOperations::of(TruthSourceOperation::Add),
            );

            return;
        }

        if ($grant->allows(TruthSourceOperation::Add)) {
            return;
        }

        self::register($collection, $grant->keys, $agentId, $grant->operations->with(TruthSourceOperation::Add));
    }

    /**
     * Unregister agent from create permission for collection
     *
     * Takes the create operation out of the agent's grant and drops the grant when nothing is
     * left of it; a wider grant keeps the rows it owns and the operations it still holds.
     *
     * @param string $collection Collection/table name
     * @param string $agentId Agent ID
     */
    public static function unregisterCreate(string $collection, string $agentId): void
    {
        $grant = self::grantOf($collection, $agentId);
        if ($grant === null || !$grant->allows(TruthSourceOperation::Add)) {
            return;
        }

        $remaining = $grant->operations->without(TruthSourceOperation::Add);
        if ($remaining->isEmpty()) {
            self::unregister($collection, $agentId);

            return;
        }

        self::register($collection, $grant->keys, $agentId, $remaining);
    }

    /**
     * Check if collection has any agent with create permission
     *
     * Create permission is a subset of write permission: an agent whose grant allows adding
     * rows may create, whether that grant also covers rows it owns or none at all.
     *
     * @param string $collection Collection/table name
     * @return bool True if at least one agent may add rows to the collection
     */
    public static function hasCreateSource(string $collection): bool
    {
        $sources = &self::getSources();
        foreach ($sources[$collection] ?? [] as $grant) {
            if ($grant->allows(TruthSourceOperation::Add)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if create operation is allowed for database table
     *
     * A grant limited to named rows cannot mint: a record that does not exist yet is not among
     * the rows it was given. Creation therefore asks for a grant that allows adding and is not
     * row-limited - the whole collection, or the mint-only claim that owns no row at all.
     *
     * @param string $collection Table name
     * @throws CreateNotAllowedException If create is not allowed
     */
    public static function checkCanCreate(string $collection): void
    {
        if (!self::hasCreateSource($collection)) {
            throw new CreateNotAllowedException(
                "Create operation not allowed: no create or write permission registered for table '{$collection}'. " .
                "Register via TruthSourceRegistry::register() or TruthSourceRegistry::registerCreate() first."
            );
        }

        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            return;
        }

        $grant = self::grantOf($collection, $agentId);
        if (
            $grant !== null
            && $grant->allows(TruthSourceOperation::Add)
            && ($grant->keys->coversEveryKey() || $grant->keys->coversNoKey())
        ) {
            return;
        }

        throw new CreateNotAllowedException(
            "Create operation not allowed: agent '{$agentId}' is not allowed to create in " .
            "table '{$collection}'."
        );
    }

    /**
     * Unregister agent from all collections and create permissions
     *
     * @param string $agentId Agent ID
     */
    public static function unregisterAgent(string $agentId): void
    {
        parent::unregisterAgent($agentId);

        ExecutionContext::clearCurrentAgentIdIf($agentId);
    }

    /**
     * Check if write operation is allowed for database table
     *
     * Three questions in order, and each fails in its own words: whether the table has a truth
     * source at all, whether the writer covers the whole table, then whether the right it holds
     * over the whole table allows this operation. Width is judged before the operation, as it is
     * for one item: first whether the rows are the writer's, then whether it may do this to them.
     *
     * @param string $collection Table name
     * @param TruthSourceOperation $operation Operation the caller is about to perform across the whole table
     * @throws WriteNotAllowedException If write is not allowed
     */
    public static function checkCanWrite(string $collection, TruthSourceOperation $operation): void
    {
        if (!self::hasTruthSource($collection)) {
            throw new WriteNotAllowedException(
                "Write operation not allowed: no truth source registered for table '{$collection}'. " .
                "Register via TruthSourceRegistry::register() first."
            );
        }

        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            if (self::getTruthSourceKeys($collection)?->coversEveryKey() !== true) {
                throw new WriteNotAllowedException(
                    "Write operation not allowed: no collection-wide truth source covers table '{$collection}'."
                );
            }

            $covering = self::operationsCoveringEveryKey($collection);
            if ($covering->allows($operation)) {
                return;
            }

            throw new WriteNotAllowedException(
                "Write operation not allowed: the collection-wide truth source for table '{$collection}' has " .
                "operations [" . $covering->asText() . "] and may not " .
                "{$operation->value} rows across the whole table."
            );
        }

        $grant = self::grantOf($collection, $agentId);
        if ($grant === null || !$grant->keys->coversEveryKey()) {
            throw new WriteNotAllowedException(
                "Write operation not allowed: agent '{$agentId}' is not a collection-wide " .
                "truth source for table '{$collection}'."
            );
        }

        if ($grant->allows($operation)) {
            return;
        }

        throw new WriteNotAllowedException(
            "Write operation not allowed: agent '{$agentId}' is a collection-wide truth source for table " .
            "'{$collection}' with operations [" . $grant->operations->asText() . "] and may not " .
            "{$operation->value} rows across the whole table."
        );
    }

    /**
     * Check if one operation on one database item id is allowed.
     *
     * Two questions in order, and they fail differently: whether the writer owns the item at
     * all, then whether the right it holds over that item covers this operation.
     *
     * The first question is asked of the row's id for a claim over the whole table or over named
     * rows, and of the row's set keys for a claim over a set: an id says nothing about whose set a
     * row is in. Every set key the write touches has to be the claim's own, so a write that moves a
     * row under another top of the set tree is refused to the owner of either set - it writes into
     * both.
     *
     * The set keys are asked of the closure only when a claim over a set is what decides - the
     * writer's own claim on the agent path, a claim over a set among the table's grants on the path
     * with no agent - and at most once: reaching the top may read the row's parent, which a writer
     * that owns the whole table or names the row may not read at all.
     *
     * @param string $collection Table name
     * @param string $idString Item id string
     * @param Closure(): list<string> $setKeys Set keys at the top of the set tree the write touches,
     *     each once, empty for a row outside every set; whatever it raises reaches the caller
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @throws WriteNotAllowedException If the item or the operation is not the caller's
     */
    public static function checkCanWriteItem(
        string $collection,
        string $idString,
        Closure $setKeys,
        TruthSourceOperation $operation,
    ): void {
        if (!self::hasTruthSource($collection)) {
            throw new WriteNotAllowedException(
                "Write operation not allowed: no truth source registered for table '{$collection}'. " .
                "Register via TruthSourceRegistry::register() first."
            );
        }

        $setKeysOnce = self::once($setKeys);
        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            if (!self::isRowCovered($collection, $idString, $setKeysOnce)) {
                throw new WriteNotAllowedException(
                    "Write operation not allowed: no truth source covers table '{$collection}' item '{$idString}'."
                );
            }

            $covering = self::operationsCoveringRow($collection, $idString, $setKeysOnce);
            if ($covering->allows($operation)) {
                return;
            }

            throw new WriteNotAllowedException(
                "Write operation not allowed: the truth source for table '{$collection}' has operations " .
                "[" . $covering->asText() . "] and may not " .
                "{$operation->value} item '{$idString}'."
            );
        }

        $grant = self::grantOf($collection, $agentId);
        if ($grant === null || !self::grantCoversRow($grant, $idString, $setKeysOnce)) {
            $reason = "Write operation not allowed: agent '{$agentId}' is not a truth source for " .
                "table '{$collection}' item '{$idString}'";
            if ($grant !== null && $grant->keys->coversSet()) {
                throw new WriteNotAllowedException(
                    "{$reason}: it holds set '{$grant->keys->setKey()}', and the item's set keys are [" .
                    implode(', ', $setKeysOnce()) . "]."
                );
            }

            throw new WriteNotAllowedException("{$reason}.");
        }

        if ($grant->allows($operation)) {
            return;
        }

        throw new WriteNotAllowedException(
            "Write operation not allowed: agent '{$agentId}' is a truth source for table '{$collection}' " .
            "with operations [" . $grant->operations->asText() . "] and may not " .
            "{$operation->value} item '{$idString}'."
        );
    }

    /**
     * Check if one statement over every row of one set is allowed.
     *
     * The same three questions in the same order as for one item: whether the table has a truth
     * source at all, whether the writer holds every row of that set, then whether its right covers
     * this operation. A claim over the whole table holds every set, a claim over a set holds the
     * statement when the value it cuts by reaches that claim's key at the top of the set tree, and
     * named rows hold none. The climb is asked of the closure only for a claim over a set, and at
     * most once.
     *
     * @param string $collection Table name
     * @param string $setKey Value of the set column the statement cuts the table by, empty for nobody's set
     * @param Closure(): list<string> $topSetKeys The key that value reaches at the top of the set tree,
     *     empty when it reaches none; whatever it raises reaches the caller
     * @param TruthSourceOperation $operation Operation the caller is about to perform on every row of the set
     * @throws WriteNotAllowedException If the set or the operation is not the caller's
     */
    public static function checkCanWriteSet(
        string $collection,
        string $setKey,
        Closure $topSetKeys,
        TruthSourceOperation $operation,
    ): void {
        if (!self::hasTruthSource($collection)) {
            throw new WriteNotAllowedException(
                "Write operation not allowed: no truth source registered for table '{$collection}'. " .
                "Register via TruthSourceRegistry::register() first."
            );
        }

        $topSetKeysOnce = self::once($topSetKeys);
        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            if (!self::isSetCovered($collection, $setKey, $topSetKeysOnce)) {
                throw new WriteNotAllowedException(
                    "Write operation not allowed: no truth source covers table '{$collection}' set '{$setKey}'."
                );
            }

            $covering = self::operationsCoveringSet($collection, $setKey, $topSetKeysOnce);
            if ($covering->allows($operation)) {
                return;
            }

            throw new WriteNotAllowedException(
                "Write operation not allowed: the truth source for table '{$collection}' has operations " .
                "[" . $covering->asText() . "] and may not " .
                "{$operation->value} rows of set '{$setKey}'."
            );
        }

        $grant = self::grantOf($collection, $agentId);
        if ($grant === null || !self::grantCoversSet($grant, $setKey, $topSetKeysOnce)) {
            $reason = "Write operation not allowed: agent '{$agentId}' is not a truth source for " .
                "table '{$collection}' set '{$setKey}'";
            if ($grant !== null && $grant->keys->coversSet()) {
                throw new WriteNotAllowedException("{$reason}: it holds set '{$grant->keys->setKey()}'.");
            }

            throw new WriteNotAllowedException("{$reason}.");
        }

        if ($grant->allows($operation)) {
            return;
        }

        throw new WriteNotAllowedException(
            "Write operation not allowed: agent '{$agentId}' is a truth source for table '{$collection}' " .
            "with operations [" . $grant->operations->asText() . "] and may not " .
            "{$operation->value} rows of set '{$setKey}'."
        );
    }

    /**
     * Whether any grant in this process covers a write of one row.
     *
     * Asked by the agent-less path, which judges the row by the collection as a whole. The shared
     * {@see AbstractTruthSourceRegistry::isTruthSource()} is not asked here: it answers by row keys
     * alone, and so has no answer for a claim over a set.
     *
     * @param string $collection Table name
     * @param string $idString Item id string
     * @param Closure(): list<string> $setKeys Set keys the write touches, called once at most across the check
     * @return bool True when at least one grant covers the write
     */
    private static function isRowCovered(string $collection, string $idString, Closure $setKeys): bool
    {
        $sources = &self::getSources();
        foreach ($sources[$collection] ?? [] as $grant) {
            if (self::grantCoversRow($grant, $idString, $setKeys)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Operations allowed by the grants that cover a write of one row, whoever holds them.
     *
     * The union, as {@see AbstractTruthSourceRegistry::operationsCovering()} makes it, asked of the
     * row's set keys as well as its id, for the reason {@see isRowCovered()} gives.
     *
     * @param string $collection Table name
     * @param string $idString Item id string
     * @param Closure(): list<string> $setKeys Set keys the write touches, called once at most across the check
     * @return TruthSourceOperations Operations any covering grant allows, each named once
     */
    private static function operationsCoveringRow(
        string $collection,
        string $idString,
        Closure $setKeys,
    ): TruthSourceOperations {
        $sources = &self::getSources();
        $operations = new TruthSourceOperations();
        foreach ($sources[$collection] ?? [] as $grant) {
            if (!self::grantCoversRow($grant, $idString, $setKeys)) {
                continue;
            }
            $operations = $operations->merge($grant->operations);
        }

        return $operations;
    }

    /**
     * Whether one grant covers a write of one row, asking the set keys only of a claim over a set.
     *
     * A claim over the whole table or over named rows looks at no set key, so it is handed none
     * and the closure is not called for it.
     *
     * @param TruthSourceGrant $grant Grant to judge
     * @param string $idString Item id string
     * @param Closure(): list<string> $setKeys Set keys the write touches
     * @return bool True when the grant covers the write
     */
    private static function grantCoversRow(TruthSourceGrant $grant, string $idString, Closure $setKeys): bool
    {
        return $grant->keys->coversRow($idString, $grant->keys->coversSet() ? $setKeys() : []);
    }

    /**
     * Wraps the set keys of one check so they are computed at most once, however many grants ask.
     *
     * @param Closure(): list<string> $setKeys Set keys the write touches
     * @return Closure(): list<string> The same keys, remembered after the first call
     */
    private static function once(Closure $setKeys): Closure
    {
        $keys = null;

        return static function () use ($setKeys, &$keys): array {
            return $keys ??= $setKeys();
        };
    }

    /**
     * Whether any grant in this process covers one statement over every row of one set.
     *
     * Asked by the agent-less path, for the reason {@see isRowCovered()} gives: the shared
     * {@see AbstractTruthSourceRegistry::getTruthSourceKeys()} has no answer for a claim over a set.
     *
     * @param string $collection Table name
     * @param string $setKey Value of the set column the statement cuts the table by, empty for nobody's set
     * @param Closure(): list<string> $topSetKeys The key that value reaches at the top, called once at most across the check
     * @return bool True when at least one grant covers every row of the set
     */
    private static function isSetCovered(string $collection, string $setKey, Closure $topSetKeys): bool
    {
        $sources = &self::getSources();
        foreach ($sources[$collection] ?? [] as $grant) {
            if (self::grantCoversSet($grant, $setKey, $topSetKeys)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Operations allowed by the grants that cover every row of one set, whoever holds them.
     *
     * @param string $collection Table name
     * @param string $setKey Value of the set column the statement cuts the table by, empty for nobody's set
     * @param Closure(): list<string> $topSetKeys The key that value reaches at the top, called once at most across the check
     * @return TruthSourceOperations Operations any covering grant allows, each named once
     */
    private static function operationsCoveringSet(string $collection, string $setKey, Closure $topSetKeys): TruthSourceOperations
    {
        $sources = &self::getSources();
        $operations = new TruthSourceOperations();
        foreach ($sources[$collection] ?? [] as $grant) {
            if (!self::grantCoversSet($grant, $setKey, $topSetKeys)) {
                continue;
            }
            $operations = $operations->merge($grant->operations);
        }

        return $operations;
    }

    /**
     * Whether one grant covers one statement over every row of one set.
     *
     * The whole table and named rows answer by the value the statement cuts by, as they always did,
     * and never ask for the climb. A claim over a set answers by the key that value reaches at the
     * top of the set tree, and only when it reaches exactly one.
     *
     * @param TruthSourceGrant $grant Grant to judge
     * @param string $setKey Value of the set column the statement cuts the table by
     * @param Closure(): list<string> $topSetKeys The key that value reaches at the top
     * @return bool True when the grant covers every row of the set
     */
    private static function grantCoversSet(TruthSourceGrant $grant, string $setKey, Closure $topSetKeys): bool
    {
        if (!$grant->keys->coversSet()) {
            return $grant->keys->coversEveryRowOfSet($setKey);
        }

        $tops = $topSetKeys();

        return count($tops) === 1 && $grant->keys->coversEveryRowOfSet($tops[0]);
    }
}
