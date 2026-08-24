<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Hilos\Cluster\RtSyncSink;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;

/**
 * Which RT collections this node owns, as far as the daemon master knows.
 *
 * {@see RtTruthSourceRegistry} answers the same question inside one process, and that is
 * exactly why this exists: the registry lives in the worker beside the agent that registered
 * in it ({@see AbstractAgent}), while what goes on the wire between nodes is decided in the
 * master. So the workers report what their agents took ({@see WorkerRtSourceRegisteredDTO})
 * and the master keeps the answers here. Nothing reaches into another process.
 *
 * What the map is for is the one thing this node cannot otherwise notice: a replica arriving
 * for a collection this node owns FULLY means the same collection has a truth source on two
 * nodes, which the model does not allow. The frame is dropped and the split is named
 * ({@see RtSyncSink}) — a wrong answer, but the only honest one, since accepting it would let
 * two owners overwrite each other silently for as long as both keep running.
 *
 * Fully is the load-bearing word since HIL-688. A claim may cover only part of the operations —
 * a library adds and removes rows it never edits — and then another node holding the rest is
 * not a split but the arrangement working. So the map remembers not just what this node owns
 * but how completely, and only two claims of the WHOLE right can be the defect.
 */
final class RtNodeSourceMap
{
    /** @var array<string, list<string>> Collections owned on this node, keyed by owning agent */
    private array $byAgent = [];

    /** @var array<string, list<string>> Of those, the ones owned only partly, keyed by the same agent */
    private array $partialByAgent = [];

    /**
     * Records what one agent of this node owns, replacing whatever it owned before.
     *
     * @param string $agentId Agent that registered as a truth source
     * @param list<string> $collectionKeys RT collections it owns
     * @param list<string> $partialCollectionKeys Those of them it owns with only part of the operations
     */
    public function note(string $agentId, array $collectionKeys, array $partialCollectionKeys = []): void
    {
        if ($collectionKeys === []) {
            $this->release($agentId);

            return;
        }

        $this->byAgent[$agentId] = $collectionKeys;
        $this->partialByAgent[$agentId] = $partialCollectionKeys;
    }

    /**
     * Drops everything one agent owned, because it is no longer running here.
     *
     * @param string $agentId Agent that stopped
     */
    public function release(string $agentId): void
    {
        unset($this->byAgent[$agentId], $this->partialByAgent[$agentId]);
    }

    /**
     * @param string $collectionKey RT collection to ask about
     * @return bool True when an agent of this node is the truth source for it
     */
    public function owns(string $collectionKey): bool
    {
        foreach ($this->byAgent as $collectionKeys) {
            if (in_array($collectionKey, $collectionKeys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an agent of this node holds the WHOLE right over a collection.
     *
     * The question a frame from another node is judged by: a node holding only part of the
     * right has no ground to refuse the holder of the rest, while two claims of the whole right
     * are the split the map exists to name.
     *
     * @param string $collectionKey RT collection to ask about
     * @return bool True when at least one agent of this node owns it with every operation
     */
    public function ownsFully(string $collectionKey): bool
    {
        foreach ($this->byAgent as $agentId => $collectionKeys) {
            if (!in_array($collectionKey, $collectionKeys, true)) {
                continue;
            }
            if (!in_array($collectionKey, $this->partialByAgent[$agentId] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> Every RT collection this node owns, each named once
     */
    public function collections(): array
    {
        $collections = [];
        foreach ($this->byAgent as $collectionKeys) {
            foreach ($collectionKeys as $collectionKey) {
                if (!in_array($collectionKey, $collections, true)) {
                    $collections[] = $collectionKey;
                }
            }
        }

        return $collections;
    }

    /**
     * Collections this node may hand over as a whole.
     *
     * Narrower than {@see collections()} on purpose: a snapshot claims to be the collection,
     * and a partial owner's copy is not - the rows only the other owner writes may be missing
     * from it. Announcing a delta stays the wider question, because both co-owners announce
     * what each of them wrote.
     *
     * @return list<string> RT collections this node owns with every operation, each named once
     */
    public function fullyOwnedCollections(): array
    {
        $collections = [];
        foreach ($this->collections() as $collectionKey) {
            if ($this->ownsFully($collectionKey)) {
                $collections[] = $collectionKey;
            }
        }

        return $collections;
    }
}
