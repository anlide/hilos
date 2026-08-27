<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Cluster\RtSyncSink;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentId;
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
 * Fully is the load-bearing word, and it is answered on TWO axes. Which OPERATIONS a claim
 * covers is one (HIL-688): a library adds and removes rows it never edits, and another node
 * holding the rest is not a split but the arrangement working. Which ROWS it covers is the
 * other (HIL-589): a claim naming keys is ownership of those entities, so an agent owning three
 * rows of a collection leaves every other row of it to whoever writes those. A node owns a
 * collection fully when it holds all the operations over all the rows, and only two such claims
 * can be the defect.
 */
final class RtNodeSourceMap
{
    /** @var array<string, list<string>> Collections owned on this node, keyed by owning agent */
    private array $byAgent = [];

    /** @var array<string, list<string>> Of those, the ones owned only partly, keyed by the same agent */
    private array $partialByAgent = [];

    /** @var array<string, array<string, list<string>>> Of those, the ones claimed by key, keyed by the same agent */
    private array $keysByAgent = [];

    /**
     * Records what one agent of this node owns, replacing whatever it owned before.
     *
     * @param string $agentId Agent that registered as a truth source
     * @param list<string> $collectionKeys RT collections it owns
     * @param list<string> $partialCollectionKeys Those of them it owns with only part of the operations
     * @param array<string, list<string>> $keysByCollection Those of them it claimed by key, and the keys
     */
    public function note(
        string $agentId,
        array $collectionKeys,
        array $partialCollectionKeys = [],
        array $keysByCollection = [],
    ): void {
        if ($collectionKeys === []) {
            $this->release($agentId);

            return;
        }

        $this->byAgent[$agentId] = $collectionKeys;
        $this->partialByAgent[$agentId] = $partialCollectionKeys;
        $this->keysByAgent[$agentId] = $keysByCollection;
    }

    /**
     * Drops everything one agent owned, because it is no longer running here.
     *
     * @param string $agentId Agent that stopped
     */
    public function release(string $agentId): void
    {
        unset($this->byAgent[$agentId], $this->partialByAgent[$agentId], $this->keysByAgent[$agentId]);
    }

    /**
     * Whether an agent of this node is the truth source for a collection, or for one row of it.
     *
     * Asked without a row id the question is about the collection: does anything here write it
     * at all. Asked with one it narrows to that row, and only then does a claim by keys answer
     * for what it actually holds — the rows it named, and no others.
     *
     * @param string $collectionKey RT collection to ask about
     * @param ?string $stateId Row to narrow the question to, or null to ask about the collection
     * @return bool True when an agent of this node is the truth source for it
     */
    public function owns(string $collectionKey, ?string $stateId = null): bool
    {
        foreach ($this->byAgent as $agentId => $collectionKeys) {
            if (!in_array($collectionKey, $collectionKeys, true)) {
                continue;
            }
            $claimedKeys = $this->keysByAgent[$agentId][$collectionKey] ?? null;
            if ($claimedKeys === null || $stateId === null || in_array($stateId, $claimedKeys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an agent of this node holds the WHOLE right over a collection, or over one row.
     *
     * The question a frame from another node is judged by: a node holding only part of the
     * right has no ground to refuse the holder of the rest, while two claims of the whole right
     * are the split the map exists to name. Whole means both axes at once — every operation, and
     * every row — so an agent that named its keys never answers yes about the collection around
     * them, however complete its right over each of them is.
     *
     * With a row id the question narrows to that row, and that is how a delta is judged: the
     * owner of three entities refuses a frame about one of those three and lets every other row
     * of the collection through.
     *
     * @param string $collectionKey RT collection to ask about
     * @param ?string $stateId Row to narrow the question to, or null to ask about the collection
     * @return bool True when at least one agent of this node owns it with every operation
     */
    public function ownsFully(string $collectionKey, ?string $stateId = null): bool
    {
        foreach ($this->byAgent as $agentId => $collectionKeys) {
            if (!in_array($collectionKey, $collectionKeys, true)) {
                continue;
            }
            if (in_array($collectionKey, $this->partialByAgent[$agentId] ?? [], true)) {
                continue;
            }
            $claimedKeys = $this->keysByAgent[$agentId][$collectionKey] ?? null;
            if ($claimedKeys === null) {
                return true;
            }
            if ($stateId !== null && in_array($stateId, $claimedKeys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What each agent of this node owns, in the form the leader is told it (HIL-696).
     *
     * The map answers about the NODE everywhere else, because that is what a frame is judged
     * by; the leader needs it per agent, since the verdict it may reach names the one agent
     * whose claim loses. Both axes travel with each entry, unaggregated: a co-owner short of
     * an operation and a claim over three named rows are what tell a legitimate arrangement
     * from a split, and folding them into a node-level answer here would lose exactly that.
     *
     * The type and index come from the agent id, which is the only identity a worker report
     * carries; they travel beside it so the leader can address a placement frame at the agent
     * without parsing anything back.
     *
     * @return list<PeerRtClaimEntry> One entry per agent of this node that owns anything
     */
    public function claims(): array
    {
        $claims = [];
        foreach ($this->byAgent as $agentId => $collectionKeys) {
            $agent = AgentId::fromId((string)$agentId);
            $claims[] = new PeerRtClaimEntry(
                agentId: (string)$agentId,
                agentType: $agent->type,
                agentIndex: $agent->index,
                collectionKeys: $collectionKeys,
                partialCollectionKeys: $this->partialByAgent[$agentId] ?? [],
                keysByCollection: $this->keysByAgent[$agentId] ?? [],
            );
        }

        return $claims;
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
     * from it. That holds on either axis, so a collection claimed by keys is left out here too;
     * it is handed over as its own rows instead ({@see keyScopedCollections()}). Announcing a
     * delta stays the wider question, because both co-owners announce what each of them wrote.
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

    /**
     * Collections this node owns by named rows, and which rows those are.
     *
     * The other half of what may be handed over, and the scope it is handed over under: what
     * this node knows to be the whole truth is not the collection but those rows, so a snapshot
     * of them speaks for them alone and leaves the rest of the collection as the receiver found
     * it. Disjoint from {@see fullyOwnedCollections()} by construction — a collection some agent
     * here owns whole is handed over whole, and naming it twice would offer the same rows under
     * two different scopes.
     *
     * A claim short of an operation is left out on both lists alike: the rows it names are
     * written by another node too, so even about those this node's copy is not the whole truth.
     *
     * @return array<string, list<string>> RT collections owned by key, with every key claimed here
     */
    public function keyScopedCollections(): array
    {
        $keysByCollection = [];
        foreach ($this->keysByAgent as $agentId => $claims) {
            foreach ($claims as $collectionKey => $claimedKeys) {
                if (
                    !in_array($collectionKey, $this->byAgent[$agentId] ?? [], true)
                    || in_array($collectionKey, $this->partialByAgent[$agentId] ?? [], true)
                    || $this->ownsFully($collectionKey)
                ) {
                    continue;
                }
                $collected = $keysByCollection[$collectionKey] ?? [];
                foreach ($claimedKeys as $stateId) {
                    if (!in_array($stateId, $collected, true)) {
                        $collected[] = $stateId;
                    }
                }
                $keysByCollection[$collectionKey] = $collected;
            }
        }

        return $keysByCollection;
    }
}
