<?php

namespace Hilos\Database\Idea;

use Hilos\Exception\Idea\TruthSource\IdeaTruthSourceWriteNotAllowedException;

/**
 * Truth Source Registry
 * Tracks which agents are sources of truth for specific tables and keys
 */
class TruthSourceRegistry
{
    /** @var array<string, array<string, array|true>> [table => [agentId => [keys or true]]] */
    private static array $sources = [];

    /**
     * Register agent as truth source for table
     *
     * @param string $table Table name
     * @param array|true $keys Array of keys or true for all keys
     * @param string $agentId Agent ID from agent->getId()
     */
    public static function register(string $table, array|true $keys, string $agentId): void
    {
        if (!isset(self::$sources[$table])) {
            self::$sources[$table] = [];
        }
        self::$sources[$table][$agentId] = $keys;
    }

    /**
     * Unregister agent as truth source
     *
     * @param string $table Table name
     * @param string $agentId Agent ID
     */
    public static function unregister(string $table, string $agentId): void
    {
        if (isset(self::$sources[$table][$agentId])) {
            unset(self::$sources[$table][$agentId]);
            if (empty(self::$sources[$table])) {
                unset(self::$sources[$table]);
            }
        }
    }

    /**
     * Unregister agent from all tables
     *
     * @param string $agentId Agent ID
     */
    public static function unregisterAgent(string $agentId): void
    {
        foreach (self::$sources as $table => $agents) {
            if (isset($agents[$agentId])) {
                unset(self::$sources[$table][$agentId]);
                if (empty(self::$sources[$table])) {
                    unset(self::$sources[$table]);
                }
            }
        }
    }

    /**
     * Check if keys are truth source (don't need to load from DB)
     *
     * @param string $table Table name
     * @param array $keys Keys to check
     * @return bool True if all keys are truth source
     */
    public static function isTruthSource(string $table, array $keys): bool
    {
        if (!isset(self::$sources[$table])) {
            return false;
        }

        foreach (self::$sources[$table] as $agentKeys) {
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
     * Get truth source keys for table (keys that are already in memory and up-to-date)
     *
     * @param string $table Table name
     * @return array|true|null Array of keys that are truth source, true for all keys, or null if not truth source
     */
    public static function getTruthSourceKeys(string $table): array|true|null
    {
        if (!isset(self::$sources[$table])) {
            return null;
        }

        $allKeys = [];
        foreach (self::$sources[$table] as $agentKeys) {
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
     * Check if table has any registered truth source
     * Used for LAZY_STRATEGY_NONE to determine if write operations are allowed
     *
     * @param string $table Table name
     * @return bool True if table has at least one registered truth source
     */
    public static function hasTruthSource(string $table): bool
    {
        return isset(self::$sources[$table]) && !empty(self::$sources[$table]);
    }

    /**
     * Check if write operation is allowed for table
     * Throws exception if no truth source is registered
     *
     * @param string $table Table name
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed (no truth source registered)
     */
    public static function checkCanWrite(string $table): void
    {
        if (!self::hasTruthSource($table)) {
            throw new IdeaTruthSourceWriteNotAllowedException("Write operation not allowed: no truth source registered for table '{$table}'. Register via TruthSourceRegistry::register() first.");
        }
    }
}
