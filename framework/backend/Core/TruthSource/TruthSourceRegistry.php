<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

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
     * @param string $collection Table name
     * @param string $idString Item id string
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @throws WriteNotAllowedException If the item or the operation is not the caller's
     */
    public static function checkCanWriteItem(
        string $collection,
        string $idString,
        TruthSourceOperation $operation,
    ): void {
        if (!self::hasTruthSource($collection)) {
            throw new WriteNotAllowedException(
                "Write operation not allowed: no truth source registered for table '{$collection}'. " .
                "Register via TruthSourceRegistry::register() first."
            );
        }

        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            if (!self::isTruthSource($collection, [$idString])) {
                throw new WriteNotAllowedException(
                    "Write operation not allowed: no truth source covers table '{$collection}' item '{$idString}'."
                );
            }

            $covering = self::operationsCovering($collection, $idString);
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
        if ($grant === null || !$grant->keys->covers($idString)) {
            throw new WriteNotAllowedException(
                "Write operation not allowed: agent '{$agentId}' is not a truth source for " .
                "table '{$collection}' item '{$idString}'."
            );
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
}
