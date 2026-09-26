<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\AbstractTruthSourceRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceGrant;
use Hilos\Core\TruthSource\TruthSourceKeys;
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
 *   // On the agent class, not in a call: {@see OwnershipDeclaration::claimRt()} reads it and
 *   // registers the claim before onStart() runs.
 *   public const array OWNS_RT = ['connections' => [TruthSourceOperation::Update]];
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
     * The claim is always the whole collection, and there is no parameter offering otherwise: a
     * singleton is not owned by halves, and none of this method's callers ever named rows.
     *
     * @param string $collection Runtime collection name
     */
    public static function registerDaemon(string $collection): void
    {
        self::register($collection, TruthSourceKeys::all(), self::DAEMON_SOURCE_ID);
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
     * Whether an agent owns a whole collection or three of its keys, the collection has an owner
     * in this process, and that is the first of the two questions the node-level map
     * ({@see RtNodeSourceMap}) puts to it. How wide that claim runs is the second, and it is
     * answered separately by {@see keysByCollectionOf()}: an agent registered for three keys owns
     * those three entities, not the collection around them.
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
            if (!$grant->operations->isComplete()) {
                $collections[] = (string)$collection;
            }
        }

        return $collections;
    }

    /**
     * Lists the rows one agent claimed by name, collection by collection.
     *
     * The width axis of the same registration {@see collectionsOf()} names, and asked for the
     * node-level map: a claim by keys is ownership of those entities, so the node holding it may
     * neither refuse another node's rows nor hand over the collection as a whole. A claim on the
     * whole collection names no keys and is absent here — silence means "all of it", the way it
     * does in the grant itself.
     *
     * A claim over a set is here with an empty list of keys, and on purpose: the node-level map
     * reads it as a claim that speaks for no row of its collection - no snapshot of the set is
     * handed over, and no foreign frame is refused. Left out, it would read as a claim over the
     * whole collection, and the snapshot that follows would wipe the other nodes' sets. Handing a
     * set over by snapshot is HIL-1116.
     *
     * @param string $agentId Agent to ask about
     * @return array<string, list<string>> Collections it claimed by key, and the keys of each
     */
    public static function keysByCollectionOf(string $agentId): array
    {
        $keysByCollection = [];
        $sources = &self::getSources();
        foreach ($sources as $collection => $agents) {
            $grant = $agents[$agentId] ?? null;
            if ($grant === null || $grant->keys->coversEveryKey()) {
                continue;
            }
            $keysByCollection[(string)$collection] = $grant->keys->listedKeys();
        }

        return $keysByCollection;
    }

    /**
     * Whether one agent's claim over a runtime collection covers one operation.
     *
     * The operation axis of {@see collectionsOf()}, and asked by a caller deciding whether a
     * whole piece of work is its to do: since one collection may have two owners holding
     * different rights over it (HIL-771), "is registered here" stopped being the same question
     * as "may do this here". The per-row guard {@see checkCanWriteState()} answers the same
     * thing with an exception, which is what a write that should have been allowed deserves and
     * not what a caller asking whether to start deserves.
     *
     * @param string $collection Runtime collection name
     * @param string $agentId Agent to ask about
     * @param TruthSourceOperation $operation Operation the agent would perform
     * @return bool True when the agent holds a claim on that collection allowing that operation
     */
    public static function allowsOperation(
        string $collection,
        string $agentId,
        TruthSourceOperation $operation,
    ): bool {
        return self::grantOf($collection, $agentId)?->allows($operation) === true;
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
            if (self::getTruthSourceKeys($collection)?->coversEveryKey() === true) {
                return;
            }

            throw new RtTruthSourceWriteNotAllowedException(
                "Write operation not allowed: no collection-wide truth source covers runtime collection " .
                "'{$collection}'."
            );
        }

        if (self::getCurrentAgentKeys($collection)?->coversEveryKey() === true) {
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
     * The first question is asked of the row's id for a claim over the whole collection or over
     * named rows, and of the row's set keys for a claim over a set: an id says nothing about whose
     * set a row is in. Every set key the write touches has to be the claim's own, so a write that
     * moves a row to another set is refused to the owner of either set - it writes into both.
     *
     * The set keys come as a list and not as a closure, unlike on the database half: a runtime row
     * has no set tree, so its keys are read off the row itself and cost nothing to hand over.
     *
     * @param string $collection Collection name
     * @param string $stateId Runtime state id
     * @param list<string> $setKeys Set keys the write touches, each once, empty for a row outside every set
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @throws RtTruthSourceWriteNotAllowedException If the row or the operation is not the caller's
     */
    public static function checkCanWriteState(
        string $collection,
        string $stateId,
        array $setKeys,
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
            if (!self::isStateCovered($collection, $stateId, $setKeys)) {
                throw new RtTruthSourceWriteNotAllowedException(
                    "Write operation not allowed: no truth source covers runtime collection '{$collection}' " .
                    "state '{$stateId}'."
                );
            }

            $covering = self::operationsCovering($collection, $stateId, $setKeys);
            if ($covering->allows($operation)) {
                return;
            }

            throw new RtTruthSourceWriteNotAllowedException(
                "Write operation not allowed: the truth source for runtime collection '{$collection}' has " .
                "operations [" . $covering->asText() . "] and may not " .
                "{$operation->value} state '{$stateId}'."
            );
        }

        $grant = self::grantOf($collection, $agentId);
        if ($grant === null || !$grant->keys->coversRow($stateId, $setKeys)) {
            $reason = "Write operation not allowed: agent '{$agentId}' is not a truth source for " .
                "runtime collection '{$collection}' state '{$stateId}'";
            if ($grant !== null && $grant->keys->coversSet()) {
                throw new RtTruthSourceWriteNotAllowedException(
                    "{$reason}: it holds set '{$grant->keys->setKey()}', and the state's set keys are [" .
                    implode(', ', $setKeys) . "]."
                );
            }

            throw new RtTruthSourceWriteNotAllowedException("{$reason}.");
        }

        if ($grant->allows($operation)) {
            return;
        }

        throw new RtTruthSourceWriteNotAllowedException(
            "Write operation not allowed: agent '{$agentId}' is a truth source for runtime collection " .
            "'{$collection}' with operations [" . $grant->operations->asText() . "] and " .
            "may not {$operation->value} state '{$stateId}'."
        );
    }

    /**
     * Whether any grant in this process covers a write of one row.
     *
     * Asked by the agent-less path, which judges the row by the collection as a whole. The shared
     * {@see AbstractTruthSourceRegistry::isTruthSource()} is not asked here: it answers by row keys
     * alone, and so has no answer for a claim over a set.
     *
     * @param string $collection Collection name
     * @param string $stateId Runtime state id
     * @param list<string> $setKeys Set keys the write touches, empty for a row outside every set
     * @return bool True when at least one grant covers the write
     */
    private static function isStateCovered(string $collection, string $stateId, array $setKeys): bool
    {
        $sources = &self::getSources();
        foreach ($sources[$collection] ?? [] as $grant) {
            if ($grant->keys->coversRow($stateId, $setKeys)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the current agent's registered key set for a collection.
     *
     * @param string $collection Collection name
     * @return ?TruthSourceKeys Width of the current agent's claim, or null when it holds none
     */
    private static function getCurrentAgentKeys(string $collection): ?TruthSourceKeys
    {
        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            return null;
        }

        return self::grantOf($collection, $agentId)?->keys;
    }
}
