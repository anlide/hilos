<?php

namespace Hilos\Core\TruthSource;

use Exception;

/**
 * Abstract Truth Source Registry.
 *
 * Base class for tracking which agents are sources of truth for specific collections.
 * Provides common logic for registration, unregistration, and permission checking.
 *
 * Child classes must:
 *   1. Define their own static $sources array
 *   2. Implement getSources() to return reference to their storage
 *   3. Implement checkCanWrite() with their specific exception type
 *
 * Usage pattern:
 *   - Agent registers as truth source on start: Registry::register($collection, true, $agentId).
 *   - WorkerManager unregisters the agent after its onStop hook: Registry::unregisterAgent($agentId).
 *   - Actions check before write: Registry::checkCanWrite($collection).
 *
 * One registration is a {@see TruthSourceGrant}: the rows it covers and the operations it
 * allows on them. A registration that names no operations gets {@see TruthSourceOperation::ALL},
 * which is what every source held before the operation axis existed.
 */
abstract class AbstractTruthSourceRegistry
{
    /**
     * Get sources storage reference.
     *
     * Each child class must override this to return reference to its own static $sources array.
     * This allows proper static inheritance with separate storage per class.
     *
     * @return array<string, array<string, TruthSourceGrant>> Reference to [collection => [agentId => grant]]
     */
    abstract protected static function &getSources(): array;

    /**
     * Register agent as truth source for collection.
     *
     * A repeated registration replaces the agent's grant for that collection rather than
     * widening it: the last claim is the whole claim.
     *
     * @param string $collection Collection/table name
     * @param list<string>|true $keys Array of specific keys or true for all keys
     * @param string $agentId Agent ID from agent->getId()
     * @param list<TruthSourceOperation> $operations Operations the agent may perform on those keys
     */
    public static function register(
        string $collection,
        array|true $keys,
        string $agentId,
        array $operations = TruthSourceOperation::ALL,
    ): void {
        $sources = &static::getSources();
        if (!isset($sources[$collection])) {
            $sources[$collection] = [];
        }
        $sources[$collection][$agentId] = new TruthSourceGrant($keys, $operations);
    }

    /**
     * Unregister agent as truth source for specific collection.
     *
     * @param string $collection Collection/table name
     * @param string $agentId Agent ID
     */
    public static function unregister(string $collection, string $agentId): void
    {
        $sources = &static::getSources();
        if (isset($sources[$collection][$agentId])) {
            unset($sources[$collection][$agentId]);
            // PhpStorm keeps the non-empty narrowing from the isset above and
            // does not model the unset, so it wrongly flags this as always false.
            /** @noinspection PhpConditionAlreadyCheckedInspection */
            if (empty($sources[$collection])) {
                /** @noinspection PhpConditionAlreadyCheckedInspection */
                unset($sources[$collection]);
            }
        }
    }

    /**
     * Unregister agent from all collections.
     *
     * @param string $agentId Agent ID
     */
    public static function unregisterAgent(string $agentId): void
    {
        $sources = &static::getSources();
        foreach ($sources as $collection => $agents) {
            if (isset($agents[$agentId])) {
                unset($sources[$collection][$agentId]);
                // PhpStorm keeps the non-empty narrowing from the isset above and
                // does not model the unset, so it wrongly flags this as always false.
                /** @noinspection PhpConditionAlreadyCheckedInspection */
                if (empty($sources[$collection])) {
                    /** @noinspection PhpConditionAlreadyCheckedInspection */
                    unset($sources[$collection]);
                }
            }
        }
    }

    /**
     * Check if collection has any registered truth source.
     *
     * @param string $collection Collection/table name
     * @return bool True if collection has at least one registered truth source
     */
    public static function hasTruthSource(string $collection): bool
    {
        $sources = &static::getSources();
        return !empty($sources[$collection]);
    }

    /**
     * Check if keys are truth source (don't need external load).
     *
     * @param string $collection Collection/table name
     * @param list<string> $keys Keys to check
     * @return bool True if all given keys are covered by a truth source
     */
    public static function isTruthSource(string $collection, array $keys): bool
    {
        $sources = &static::getSources();
        if (!isset($sources[$collection])) {
            return false;
        }

        foreach ($sources[$collection] as $grant) {
            if ($grant->keys === true) {
                return true; // All keys are truth source
            }
            $allKeysPresent = true;
            foreach ($keys as $key) {
                if (!in_array($key, $grant->keys, true)) {
                    $allKeysPresent = false;
                    break;
                }
            }
            if ($allKeysPresent) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get truth source keys for collection.
     *
     * @param string $collection Collection/table name
     * @return list<string>|true|null Array of keys, true for all keys, or null if no truth source
     */
    public static function getTruthSourceKeys(string $collection): array|true|null
    {
        $sources = &static::getSources();
        if (!isset($sources[$collection])) {
            return null;
        }

        $allKeys = [];
        foreach ($sources[$collection] as $grant) {
            if ($grant->keys === true) {
                return true; // All keys are truth source
            }
            $allKeys = array_merge($allKeys, $grant->keys);
        }

        return empty($allKeys) ? null : array_unique($allKeys);
    }

    /**
     * @param string $collection Collection/table name
     * @param string $agentId Agent ID
     * @return ?TruthSourceGrant The agent's grant for this collection, or null when it holds none
     */
    protected static function grantOf(string $collection, string $agentId): ?TruthSourceGrant
    {
        $sources = &static::getSources();

        return $sources[$collection][$agentId] ?? null;
    }

    /**
     * Operations allowed by the grants that cover one key, whoever holds them.
     *
     * The union, not one grant's set: the agent-less write path is judged by the collection as
     * a whole, exactly as its width check already is.
     *
     * @param string $collection Collection/table name
     * @param string $key Row key about to be written
     * @return list<TruthSourceOperation> Operations any covering grant allows, each named once
     */
    protected static function operationsCovering(string $collection, string $key): array
    {
        $sources = &static::getSources();
        $operations = [];
        foreach ($sources[$collection] ?? [] as $grant) {
            if ($grant->keys !== true && !in_array($key, $grant->keys, true)) {
                continue;
            }
            foreach ($grant->operations as $operation) {
                if (!in_array($operation, $operations, true)) {
                    $operations[] = $operation;
                }
            }
        }

        return $operations;
    }

    /**
     * Check if write operation is allowed for collection.
     *
     * Must be implemented by child classes to throw appropriate exception type.
     *
     * @param string $collection Collection/table name
     * @throws Exception If write is not allowed (no truth source registered)
     */
    abstract public static function checkCanWrite(string $collection): void;
}
