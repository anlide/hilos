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
 * for a collection this node owns means the same collection has a truth source on two nodes,
 * which the model does not allow. The frame is dropped and the split is named
 * ({@see RtSyncSink}) — a wrong answer, but the only honest one, since accepting it would let
 * two owners overwrite each other silently for as long as both keep running.
 */
final class RtNodeSourceMap
{
    /** @var array<string, list<string>> Collections owned on this node, keyed by owning agent */
    private array $byAgent = [];

    /**
     * Records what one agent of this node owns, replacing whatever it owned before.
     *
     * @param string $agentId Agent that registered as a truth source
     * @param list<string> $collectionKeys RT collections it owns
     */
    public function note(string $agentId, array $collectionKeys): void
    {
        if ($collectionKeys === []) {
            $this->release($agentId);

            return;
        }

        $this->byAgent[$agentId] = $collectionKeys;
    }

    /**
     * Drops everything one agent owned, because it is no longer running here.
     *
     * @param string $agentId Agent that stopped
     */
    public function release(string $agentId): void
    {
        unset($this->byAgent[$agentId]);
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
}
