<?php

declare(strict_types=1);

namespace Hilos\Cluster\Consensus;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Logger;

/**
 * Immutable snapshot of the consensus configuration for the local master node.
 *
 * Consensus runs against a static expected-master-set declared in configuration,
 * not the live registry: the quorum is a fixed majority of that set, so a network
 * partition can never manufacture a second majority. The timings drive the
 * randomized election timeout (which spreads simultaneous bootstraps and avoids
 * split votes) and the leader heartbeat interval. The whole object is captured
 * once at start; consensus state itself is never persisted.
 */
final class ClusterConsensusConfig
{
    /**
     * @param string $selfNodeId Local node id (always a member of the master set)
     * @param list<string> $masterSet Expected master-set node ids that define the quorum
     * @param int $quorumSize Majority of the master set: floor(n/2)+1
     * @param int $electionTimeoutMinMs Lower bound of the randomized election timeout
     * @param int $electionTimeoutMaxMs Upper bound of the randomized election timeout
     * @param int $heartbeatIntervalMs Leader heartbeat interval; must be below the election minimum
     */
    public function __construct(
        public readonly string $selfNodeId,
        public readonly array $masterSet,
        public readonly int $quorumSize,
        public readonly int $electionTimeoutMinMs,
        public readonly int $electionTimeoutMaxMs,
        public readonly int $heartbeatIntervalMs,
    ) {
    }

    /**
     * Builds the consensus configuration for the local master node from env.
     *
     * Called only for a clustered master, so a missing or self-excluding master set
     * is a configuration error rather than a default. A master set smaller than
     * three is allowed but warned about — it is useful for tests but offers no real
     * fault tolerance. The quorum is a fixed majority of the declared set.
     *
     * @param NodeIdentity $identity Local node identity (supplies the self node id)
     * @return self Resolved consensus configuration
     * @throws ClusterConfigurationException When the master set or timings are missing or invalid
     * @throws EnvException When a consensus env value cannot be read
     */
    public static function fromEnv(NodeIdentity $identity): self
    {
        $masterSet = self::parseNodeList(Hilos::$env[EnvConstants::CLUSTER_MASTER_SET]);
        if ($masterSet === []) {
            throw ClusterConfigurationException::missingField(EnvConstants::CLUSTER_MASTER_SET->name);
        }

        if (!in_array($identity->nodeId, $masterSet, true)) {
            throw ClusterConfigurationException::nodeNotInMasterSet($identity->nodeId);
        }

        if (count($masterSet) < 3) {
            Logger::warning(sprintf(
                'CLUSTER_MASTER_SET has %d nodes; at least 3 are needed for real fault tolerance',
                count($masterSet),
            ));
        }

        $minMs = Hilos::$env->int(EnvConstants::CLUSTER_ELECTION_TIMEOUT_MIN_MS);
        $maxMs = Hilos::$env->int(EnvConstants::CLUSTER_ELECTION_TIMEOUT_MAX_MS);
        $heartbeatMs = Hilos::$env->int(EnvConstants::CLUSTER_HEARTBEAT_INTERVAL_MS);
        self::validateTimings($minMs, $maxMs, $heartbeatMs);

        return new self(
            selfNodeId: $identity->nodeId,
            masterSet: $masterSet,
            quorumSize: intdiv(count($masterSet), 2) + 1,
            electionTimeoutMinMs: $minMs,
            electionTimeoutMaxMs: $maxMs,
            heartbeatIntervalMs: $heartbeatMs,
        );
    }

    /**
     * Rejects timings that could not elect and hold a stable leader.
     *
     * The heartbeat must be shorter than the shortest election timeout, or a
     * healthy leader would be declared lost between heartbeats; the election window
     * must be a positive, non-inverted range so the randomized timeout is well
     * defined.
     *
     * @param int $minMs Election timeout lower bound
     * @param int $maxMs Election timeout upper bound
     * @param int $heartbeatMs Leader heartbeat interval
     * @throws ClusterConfigurationException When the timings cannot elect a stable leader
     */
    private static function validateTimings(int $minMs, int $maxMs, int $heartbeatMs): void
    {
        if ($minMs <= 0 || $heartbeatMs <= 0) {
            throw ClusterConfigurationException::invalidTiming('election and heartbeat timings must be positive');
        }

        if ($minMs > $maxMs) {
            throw ClusterConfigurationException::invalidTiming("election minimum {$minMs}ms exceeds maximum {$maxMs}ms");
        }

        if ($heartbeatMs >= $minMs) {
            throw ClusterConfigurationException::invalidTiming(
                "heartbeat {$heartbeatMs}ms must be below the election minimum {$minMs}ms",
            );
        }
    }

    /**
     * Parses a comma-separated node-id list into a normalized, de-duplicated list.
     *
     * @param string $raw Raw comma-separated node-id configuration value
     * @return list<string> Normalized node ids
     */
    private static function parseNodeList(string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $id) {
            $id = trim($id);
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
