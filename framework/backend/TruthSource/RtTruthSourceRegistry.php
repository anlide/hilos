<?php

namespace Hilos\TruthSource;

use Hilos\Core\TruthSource\AbstractTruthSourceRegistry;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;

/**
 * Runtime Truth Source Registry
 *
 * Tracks which agents are sources of truth for runtime collections.
 * Only registered agents can write to runtime data.
 *
 * Usage:
 *   // In Agent::onStart()
 *   RtTruthSourceRegistry::register('connections', true, $this->getId());
 *
 *   // In Agent::onStop()
 *   RtTruthSourceRegistry::unregisterAgent($this->getId());
 *
 *   // In RtActions (automatic check)
 *   RtTruthSourceRegistry::checkCanWrite('connections');
 */
class RtTruthSourceRegistry extends AbstractTruthSourceRegistry
{
    /** @var array<string, array<string, array|true>> [collection => [agentId => keys]] */
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
     * Check if write operation is allowed for runtime collection
     *
     * @param string $collection Collection name
     * @throws RtTruthSourceWriteNotAllowedException If write is not allowed
     */
    public static function checkCanWrite(string $collection): void
    {
        if (!self::hasTruthSource($collection)) {
            throw new RtTruthSourceWriteNotAllowedException(
                "Write operation not allowed: no truth source registered for runtime collection '{$collection}'. " .
                "Register via RtTruthSourceRegistry::register() first."
            );
        }
    }
}
