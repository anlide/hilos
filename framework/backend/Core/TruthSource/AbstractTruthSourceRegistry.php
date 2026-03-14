<?php

namespace Hilos\Core\TruthSource;

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
 *   - Agent unregisters on stop: Registry::unregisterAgent($agentId).
 *   - Actions check before write: Registry::checkCanWrite($collection).
 */
abstract class AbstractTruthSourceRegistry
{
    /**
     * Get sources storage reference.
     *
     * Each child class must override this to return reference to its own static $sources array.
     * This allows proper static inheritance with separate storage per class.
     *
     * @return array<string, array<string, array|true>> Reference to [collection => [agentId => keys]]
     */
    abstract protected static function &getSources(): array;

    /**
     * Register agent as truth source for collection.
     *
     * @param string $collection Collection/table name
     * @param array<int, string>|true $keys Array of specific keys or true for all keys
     * @param string $agentId Agent ID from agent->getId()
     */
    public static function register(string $collection, array|true $keys, string $agentId): void
    {
        $sources = &static::getSources();
        if (!isset($sources[$collection])) {
            $sources[$collection] = [];
        }
        $sources[$collection][$agentId] = $keys;
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
            if (empty($sources[$collection])) {
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
                if (empty($sources[$collection])) {
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
        return isset($sources[$collection]) && !empty($sources[$collection]);
    }

    /**
     * Check if keys are truth source (don't need external load).
     *
     * @param string $collection Collection/table name
     * @param array<int, string> $keys Keys to check
     * @return bool True if all given keys are covered by a truth source
     */
    public static function isTruthSource(string $collection, array $keys): bool
    {
        $sources = &static::getSources();
        if (!isset($sources[$collection])) {
            return false;
        }

        foreach ($sources[$collection] as $agentKeys) {
            if ($agentKeys === true) {
                return true; // All keys are truth source
            }
            if (is_array($agentKeys)) {
                $allKeysPresent = true;
                foreach ($keys as $key) {
                    if (!in_array($key, $agentKeys, true)) {
                        $allKeysPresent = false;
                        break;
                    }
                }
                if ($allKeysPresent) {
                    return true;
                }
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
        foreach ($sources[$collection] as $agentKeys) {
            if ($agentKeys === true) {
                return true; // All keys are truth source
            }
            if (is_array($agentKeys)) {
                $allKeys = array_merge($allKeys, $agentKeys);
            }
        }

        return empty($allKeys) ? null : array_unique($allKeys);
    }

    /**
     * Check if write operation is allowed for collection.
     *
     * Must be implemented by child classes to throw appropriate exception type.
     *
     * @param string $collection Collection/table name
     * @throws \Exception If write is not allowed (no truth source registered)
     */
    abstract public static function checkCanWrite(string $collection): void;
}
