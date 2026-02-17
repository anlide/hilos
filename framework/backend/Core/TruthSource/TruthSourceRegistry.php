<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;

/**
 * Database Truth Source Registry
 *
 * Tracks which agents are sources of truth for specific database tables.
 * Only registered agents can write to database tables.
 *
 * Usage:
 *   // In Agent::onStart()
 *   TruthSourceRegistry::register(Hilos::users, true, $this->getId());
 *
 *   // In Agent::onStop()
 *   TruthSourceRegistry::unregisterAgent($this->getId());
 *
 *   // In DbActions (automatic check)
 *   TruthSourceRegistry::checkCanWrite($tableName);
 */
class TruthSourceRegistry extends AbstractTruthSourceRegistry
{
    /** @var array<string, array<string, array|true>> [table => [agentId => keys]] */
    private static array $sources = [];

    /**
     * Get sources storage reference
     *
     * @return array<string, array<string, array|true>>
     */
    protected static function &getSources(): array
    {
        return self::$sources;
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
    }
}
