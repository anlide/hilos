<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Facade context for cluster mode and the local node identity.
 *
 * This is the single seam through which the rest of the framework asks "are we
 * clustered, and who am I". It is always present on the facade; when cluster
 * mode is off it simply reports disabled and holds no identity, so a single-node
 * daemon carries this context at zero behavioural cost. Later cluster slices
 * (the live node registry, the peer channel, the coordinator) hang off this same
 * context rather than adding further facade globals.
 *
 * Configuration is read lazily from Hilos::$env, not captured at construction:
 * the daemon overlays a test env after the facade is bootstrapped, so eager
 * reads would miss it.
 */
final class ClusterContext
{
    /** @var ?NodeIdentity Resolved local node identity, memoized on first access. */
    private ?NodeIdentity $identity = null;

    /** @var ?ClusterRegistry Master-owned live membership registry, built on first access. */
    private ?ClusterRegistry $registry = null;

    /**
     * @return bool True when cluster mode is enabled
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function isEnabled(): bool
    {
        return Hilos::$env->bool(EnvConstants::CLUSTER_ENABLED);
    }

    /**
     * @return NodeIdentity Local node identity
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     */
    public function identity(): NodeIdentity
    {
        if (!$this->isEnabled()) {
            throw new ClusterDisabledException('Node identity is unavailable while cluster mode is disabled');
        }

        return $this->identity ??= NodeIdentity::fromEnv();
    }

    /**
     * Returns the master-owned live membership registry, seeded with the local node.
     *
     * The registry is the single source of truth for cluster membership and lives
     * on the daemon master; it is built lazily and self-seeds the local node on
     * first access. The peer transport records and removes peers through it.
     *
     * @return ClusterRegistry Live membership registry
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     */
    public function registry(): ClusterRegistry
    {
        if (!$this->isEnabled()) {
            throw new ClusterDisabledException('The cluster registry is unavailable while cluster mode is disabled');
        }

        if ($this->registry === null) {
            $this->registry = new ClusterRegistry();
            $this->registry->seedLocal($this->identity(), microtime(true));
        }

        return $this->registry;
    }

    /**
     * Builds the cluster node snapshot answered by the `cluster:nodes` command.
     *
     * Reads the live membership registry (local node plus any connected peers)
     * when enabled, and reports an empty set when cluster mode is off.
     *
     * @return array{enabled: bool, nodes: list<array<string, mixed>>} Node snapshot payload
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     */
    public function snapshot(): array
    {
        if (!$this->isEnabled()) {
            return [
                ClusterCommandConstants::FIELD_ENABLED => false,
                ClusterCommandConstants::FIELD_NODES => [],
            ];
        }

        $nodes = [];
        foreach ($this->registry()->snapshot() as $node) {
            $nodes[] = [
                ClusterCommandConstants::FIELD_NODE_ID => $node->nodeId,
                ClusterCommandConstants::FIELD_NODE_ROLE => $node->role->value,
                ClusterCommandConstants::FIELD_NODE_CAPABILITIES => $node->capabilities,
                ClusterCommandConstants::FIELD_NODE_ONLINE => $node->online,
            ];
        }

        return [
            ClusterCommandConstants::FIELD_ENABLED => true,
            ClusterCommandConstants::FIELD_NODES => $nodes,
        ];
    }
}
