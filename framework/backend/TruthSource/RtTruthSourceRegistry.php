<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\AbstractTruthSourceRegistry;
use Hilos\Core\TruthSource\TruthSourceGrant;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;

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
    /**
     * Synthetic truth-source id for the daemon master writing an RT collection it owns
     * directly, with no agent behind the write.
     *
     * Most runtime collections are owned by an agent, which registers under its
     * {@see AbstractAgent::getId()}. A framework singleton such as the
     * protected-mode runtime is instead written by the daemon master itself: the leader
     * writes it by its own decision and each follower writes it in reaction to a peer
     * frame, so no agent stands behind the write. The write-guard's agent-less branch
     * ({@see checkCanWriteState()} when {@see ExecutionContext::currentAgentId()} is null)
     * accepts a collection-wide source, so the master registers one under this stable id.
     */
    public const string DAEMON_SOURCE_ID = 'hilos:daemon-master';

    /** @var array<string, array<string, TruthSourceGrant>> [collection => [agentId => grant]] */
    private static array $sources = [];

    /**
     * Get sources storage reference.
     *
     * @return array<string, array<string, TruthSourceGrant>> Reference to storage [collection => [agentId => grant]]
     */
    protected static function &getSources(): array
    {
        return self::$sources;
    }

    /**
     * Register the daemon master as the non-agent truth source for a runtime collection.
     *
     * The daemon master owns framework singletons that no agent writes (see
     * {@see self::DAEMON_SOURCE_ID}); this registers it as a collection-wide source so its own
     * agent-less {@see RtState::sync()} calls pass the write-guard. It holds every operation:
     * a singleton the master owns is one it also brings into being and clears.
     *
     * @param string $collection Runtime collection name
     * @param list<string>|true $keys Specific writable keys or true for all keys
     */
    public static function registerDaemon(string $collection, array|true $keys = true): void
    {
        self::register($collection, $keys, self::DAEMON_SOURCE_ID);
    }

    /**
     * Unregister the daemon master as the truth source for a runtime collection.
     *
     * @param string $collection Runtime collection name
     */
    public static function unregisterDaemon(string $collection): void
    {
        self::unregister($collection, self::DAEMON_SOURCE_ID);
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
     * Lists the runtime collections one agent is a truth source for.
     *
     * The granularity of the registration is dropped on purpose: whether an agent owns a whole
     * collection or three of its keys, the collection has an owner in this process, and that is
     * the whole question the node-level map ({@see RtNodeSourceMap}) puts to it.
     *
     * @param string $agentId Agent to ask about
     * @return list<string> Collections it is registered for, each named once
     */
    public static function collectionsOf(string $agentId): array
    {
        $collections = [];
        $sources = &self::getSources();
        foreach ($sources as $collection => $agents) {
            if (isset($agents[$agentId])) {
                $collections[] = (string)$collection;
            }
        }

        return $collections;
    }

    /**
     * Lists the runtime collections one agent owns with less than the full set of operations.
     *
     * A subset of what {@see collectionsOf()} answers, and asked for the node-level map: a
     * partial right is one another node may legitimately hold the rest of, so a replica arriving
     * for such a collection is not the two-owner split the map otherwise refuses.
     *
     * @param string $agentId Agent to ask about
     * @return list<string> Collections it holds a partial right over, each named once
     */
    public static function partialCollectionsOf(string $agentId): array
    {
        $collections = [];
        $sources = &self::getSources();
        foreach ($sources as $collection => $agents) {
            $grant = $agents[$agentId] ?? null;
            if ($grant === null) {
                continue;
            }
            foreach (TruthSourceOperation::ALL as $operation) {
                if (!$grant->allows($operation)) {
                    $collections[] = (string)$collection;
                    break;
                }
            }
        }

        return $collections;
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

        if (ExecutionContext::currentAgentId() === null) {
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
            "Write operation not allowed: agent '" . ExecutionContext::currentAgentId() . "' is not a collection-wide " .
            "truth source for runtime collection '{$collection}'."
        );
    }

    /**
     * Check if one operation on one runtime state id is allowed.
     *
     * Two questions in order, and they fail differently: whether the writer owns the row at all,
     * then whether the right it holds over that row covers this operation.
     *
     * @param string $collection Collection name
     * @param string $stateId Runtime state id
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @throws RtTruthSourceWriteNotAllowedException If the row or the operation is not the caller's
     */
    public static function checkCanWriteState(
        string $collection,
        string $stateId,
        TruthSourceOperation $operation,
    ): void {
        if (!self::hasTruthSource($collection)) {
            throw new RtTruthSourceWriteNotAllowedException(
                "Write operation not allowed: no truth source registered for runtime collection '{$collection}'. " .
                "Register via RtTruthSourceRegistry::register() first."
            );
        }

        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            if (!self::isTruthSource($collection, [$stateId])) {
                throw new RtTruthSourceWriteNotAllowedException(
                    "Write operation not allowed: no truth source covers runtime collection '{$collection}' " .
                    "state '{$stateId}'."
                );
            }

            $covering = self::operationsCovering($collection, $stateId);
            if (in_array($operation, $covering, true)) {
                return;
            }

            throw new RtTruthSourceWriteNotAllowedException(
                "Write operation not allowed: the truth source for runtime collection '{$collection}' has " .
                "operations [" . TruthSourceOperation::listAsText($covering) . "] and may not " .
                "{$operation->value} state '{$stateId}'."
            );
        }

        $grant = self::grantOf($collection, $agentId);
        if ($grant === null || ($grant->keys !== true && !in_array($stateId, $grant->keys, true))) {
            throw new RtTruthSourceWriteNotAllowedException(
                "Write operation not allowed: agent '{$agentId}' is not a truth source for " .
                "runtime collection '{$collection}' state '{$stateId}'."
            );
        }

        if ($grant->allows($operation)) {
            return;
        }

        throw new RtTruthSourceWriteNotAllowedException(
            "Write operation not allowed: agent '{$agentId}' is a truth source for runtime collection " .
            "'{$collection}' with operations [" . TruthSourceOperation::listAsText($grant->operations) . "] and " .
            "may not {$operation->value} state '{$stateId}'."
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
        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            return null;
        }

        return self::grantOf($collection, $agentId)?->keys;
    }
}
