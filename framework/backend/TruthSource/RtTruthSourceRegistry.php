<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\AbstractTruthSourceRegistry;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;

/**
 * Runtime Truth Source Registry.
 *
 * Tracks which agents are sources of truth for runtime collections.
 * Only registered agents can write to runtime data.
 *
 * Usage:
 *   // In Agent::onStart()
 *   RtTruthSourceRegistry::register('connections', true, $this->getId());
 *
 *   // After Agent::onStop() returns or throws, WorkerManager unregisters the agent.
 *   RtTruthSourceRegistry::unregisterAgent($agent->getId());
 *
 *   // In RtActions (automatic check)
 *   RtTruthSourceRegistry::checkCanWrite('connections');
 */
class RtTruthSourceRegistry extends AbstractTruthSourceRegistry
{
    /** @var array<string, array<string, array|true>> [collection => [agentId => keys]] */
    private static array $sources = [];

    /**
     * Get sources storage reference.
     *
     * @return array<string, array<string, array|true>> Reference to sources storage [collection => [agentId => keys]]
     */
    protected static function &getSources(): array
    {
        return self::$sources;
    }

    /**
     * Set the agent whose code is currently executing.
     *
     * @param ?string $agentId Current agent id, or null outside an agent callback
     */
    public static function setCurrentAgentId(?string $agentId): void
    {
        ExecutionContext::setCurrentAgentId($agentId);
    }

    /**
     * Returns the agent whose code is currently executing.
     *
     * @return ?string Current agent id, or null outside an agent callback
     */
    public static function getCurrentAgentId(): ?string
    {
        return ExecutionContext::currentAgentId();
    }

    /**
     * Unregister agent and clear it from the current RT writer context.
     *
     * @param string $agentId Agent ID
     */
    public static function unregisterAgent(string $agentId): void
    {
        parent::unregisterAgent($agentId);

        ExecutionContext::clearCurrentAgentIdIf($agentId);
    }

    /**
     * Check if collection-wide write operation is allowed for runtime collection.
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

        if (self::getCurrentAgentId() === null) {
            if (self::getTruthSourceKeys($collection) === true) {
                return;
            }

            throw new RtTruthSourceWriteNotAllowedException(
                "Write operation not allowed: no collection-wide truth source covers runtime collection " .
                "'{$collection}'."
            );
        }

        $agentKeys = self::getCurrentAgentKeys($collection);
        if ($agentKeys === true) {
            return;
        }

        throw new RtTruthSourceWriteNotAllowedException(
            "Write operation not allowed: agent '" . self::getCurrentAgentId() . "' is not a collection-wide " .
            "truth source for runtime collection '{$collection}'."
        );
    }

    /**
     * Check if write operation is allowed for one runtime state id.
     *
     * @param string $collection Collection name
     * @param string $stateId Runtime state id
     * @throws RtTruthSourceWriteNotAllowedException If write is not allowed
     */
    public static function checkCanWriteState(string $collection, string $stateId): void
    {
        if (!self::hasTruthSource($collection)) {
            throw new RtTruthSourceWriteNotAllowedException(
                "Write operation not allowed: no truth source registered for runtime collection '{$collection}'. " .
                "Register via RtTruthSourceRegistry::register() first."
            );
        }

        if (self::getCurrentAgentId() === null) {
            if (self::isTruthSource($collection, [$stateId])) {
                return;
            }

            throw new RtTruthSourceWriteNotAllowedException(
                "Write operation not allowed: no truth source covers runtime collection '{$collection}' " .
                "state '{$stateId}'."
            );
        }

        $agentKeys = self::getCurrentAgentKeys($collection);
        if ($agentKeys === true || (is_array($agentKeys) && in_array($stateId, $agentKeys, true))) {
            return;
        }

        throw new RtTruthSourceWriteNotAllowedException(
            "Write operation not allowed: agent '" . self::getCurrentAgentId() . "' is not a truth source for " .
            "runtime collection '{$collection}' state '{$stateId}'."
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
        if (self::getCurrentAgentId() === null) {
            return null;
        }

        $sources = &self::getSources();

        return $sources[$collection][self::getCurrentAgentId()] ?? null;
    }
}
