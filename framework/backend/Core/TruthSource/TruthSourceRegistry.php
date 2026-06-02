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
 * Create permission: separate right for INSERT operations (key does not exist yet).
 * Register via registerCreate() for agents that can create new records.
 *
 * Usage:
 *   // In Agent::onStart()
 *   TruthSourceRegistry::register(Hilos::users, true, $this->getId());
 *   TruthSourceRegistry::registerCreate(Hilos::bots, $this->getId());
 *
 *   // After Agent::onStop() returns or throws, WorkerManager unregisters the agent.
 *   TruthSourceRegistry::unregisterAgent($agent->getId());
 *
 *   // In DbActions (automatic check)
 *   TruthSourceRegistry::checkCanWrite($tableName);
 *   TruthSourceRegistry::checkCanCreate($tableName);
 */
class TruthSourceRegistry extends AbstractTruthSourceRegistry
{
    /** @var array<string, array<string, array|true>> [table => [agentId => keys]] */
    private static array $sources = [];

    /** @var array<string, array<string, true>> [collection => [agentId => true]] Create permission registry */
    private static array $createSources = [];

    /**
     * Get sources storage reference.
     *
     * @return array<string, array<string, array|true>> Reference to table→agentId→keys mapping
     */
    protected static function &getSources(): array
    {
        return self::$sources;
    }

    /**
     * Register agent as having create permission for collection
     *
     * @param string $collection Collection/table name
     * @param string $agentId Agent ID from agent->getId()
     */
    public static function registerCreate(string $collection, string $agentId): void
    {
        if (!isset(self::$createSources[$collection])) {
            self::$createSources[$collection] = [];
        }
        self::$createSources[$collection][$agentId] = true;
    }

    /**
     * Unregister agent from create permission for collection
     *
     * @param string $collection Collection/table name
     * @param string $agentId Agent ID
     */
    public static function unregisterCreate(string $collection, string $agentId): void
    {
        if (isset(self::$createSources[$collection][$agentId])) {
            unset(self::$createSources[$collection][$agentId]);
            if (empty(self::$createSources[$collection])) {
                unset(self::$createSources[$collection]);
            }
        }
    }

    /**
     * Check if collection has any agent with create permission
     *
     * Create permission is a subset of write permission: if an agent has truth source
     * (write rights) for the collection, it implicitly has create permission.
     *
     * @param string $collection Collection/table name
     * @return bool True if at least one agent has create or write permission
     */
    public static function hasCreateSource(string $collection): bool
    {
        return self::hasTruthSource($collection)
            || (isset(self::$createSources[$collection]) && !empty(self::$createSources[$collection]));
    }

    /**
     * Check if create operation is allowed for database table
     *
     * Create is allowed if any agent has write permission (truth source) or explicit create permission.
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

        if (ExecutionContext::currentAgentId() === null) {
            return;
        }

        if (isset(self::$createSources[$collection][ExecutionContext::currentAgentId()])) {
            return;
        }

        if (self::getCurrentAgentKeys($collection) === true) {
            return;
        }

        throw new CreateNotAllowedException(
            "Create operation not allowed: agent '" . ExecutionContext::currentAgentId() . "' is not allowed to create in " .
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

        foreach (self::$createSources as $collection => $agents) {
            if (isset($agents[$agentId])) {
                unset(self::$createSources[$collection][$agentId]);
                if (empty(self::$createSources[$collection])) {
                    unset(self::$createSources[$collection]);
                }
            }
        }

        ExecutionContext::clearCurrentAgentIdIf($agentId);
    }

    /**
     * Check if write operation is allowed for database table
     *
     * @param string $collection Table name
     * @throws WriteNotAllowedException If write is not allowed
     */
    public static function checkCanWrite(string $collection): void
    {
        if (!self::hasTruthSource($collection)) {
            throw new WriteNotAllowedException(
                "Write operation not allowed: no truth source registered for table '{$collection}'. " .
                "Register via TruthSourceRegistry::register() first."
            );
        }

        if (ExecutionContext::currentAgentId() === null) {
            if (self::getTruthSourceKeys($collection) === true) {
                return;
            }

            throw new WriteNotAllowedException(
                "Write operation not allowed: no collection-wide truth source covers table '{$collection}'."
            );
        }

        if (self::getCurrentAgentKeys($collection) === true) {
            return;
        }

        throw new WriteNotAllowedException(
            "Write operation not allowed: agent '" . ExecutionContext::currentAgentId() . "' is not a collection-wide " .
            "truth source for table '{$collection}'."
        );
    }

    /**
     * Check if write operation is allowed for one database item id.
     *
     * @param string $collection Table name
     * @param string $idString Item id string
     * @throws WriteNotAllowedException If write is not allowed
     */
    public static function checkCanWriteItem(string $collection, string $idString): void
    {
        if (!self::hasTruthSource($collection)) {
            throw new WriteNotAllowedException(
                "Write operation not allowed: no truth source registered for table '{$collection}'. " .
                "Register via TruthSourceRegistry::register() first."
            );
        }

        if (ExecutionContext::currentAgentId() === null) {
            if (self::isTruthSource($collection, [$idString])) {
                return;
            }

            throw new WriteNotAllowedException(
                "Write operation not allowed: no truth source covers table '{$collection}' item '{$idString}'."
            );
        }

        $agentKeys = self::getCurrentAgentKeys($collection);
        if ($agentKeys === true || (is_array($agentKeys) && in_array($idString, $agentKeys, true))) {
            return;
        }

        throw new WriteNotAllowedException(
            "Write operation not allowed: agent '" . ExecutionContext::currentAgentId() . "' is not a truth source for " .
            "table '{$collection}' item '{$idString}'."
        );
    }

    /**
     * Returns the current agent's registered key set for a collection.
     *
     * @param string $collection Collection name
     * @return list<string>|true|null Current agent keys, true for full collection, or null
     */
    private static function getCurrentAgentKeys(string $collection): array|true|null
    {
        if (ExecutionContext::currentAgentId() === null) {
            return null;
        }

        $sources = &self::getSources();

        return $sources[$collection][ExecutionContext::currentAgentId()] ?? null;
    }
}
