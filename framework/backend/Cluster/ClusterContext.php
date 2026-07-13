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
     * Builds the cluster node snapshot answered by the `cluster:nodes` command.
     *
     * Until the live peer-join registry lands (HIL-178), the known set is just
     * the local node, so the list holds a single row when enabled and is empty
     * when cluster mode is off.
     *
     * @return array{enabled: bool, nodes: list<array<string, mixed>>} Node snapshot payload
     * @throws ClusterDisabledException When cluster mode is disabled
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

        $node = $this->identity();

        return [
            ClusterCommandConstants::FIELD_ENABLED => true,
            ClusterCommandConstants::FIELD_NODES => [
                [
                    ClusterCommandConstants::FIELD_NODE_ID => $node->nodeId,
                    ClusterCommandConstants::FIELD_NODE_ROLE => $node->role->value,
                    ClusterCommandConstants::FIELD_NODE_CAPABILITIES => $node->capabilities,
                ],
            ],
        ];
    }
}
