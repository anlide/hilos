<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Immutable self-declared identity of the local cluster node.
 *
 * Identity is "who am I", not "who else is in the cluster": it carries only the
 * node's own id, role, and declared capability tags. Membership (the live set of
 * peers) is a separate runtime registry built by peer-join and is not modelled
 * here. Capabilities are a flat tag list for now; the hard/soft matching and
 * coordinator-preference model is layered on top in HIL-182.
 */
final class NodeIdentity
{
    /**
     * @param string $nodeId Unique self-declared node id
     * @param NodeRole $role Self-declared node role
     * @param list<string> $capabilities Declared capability tags
     */
    private function __construct(
        public readonly string $nodeId,
        public readonly NodeRole $role,
        public readonly array $capabilities,
    ) {
    }

    /**
     * Builds the local node identity from cluster configuration.
     *
     * Called only when cluster mode is enabled, so a missing id or role is a
     * configuration error rather than a single-node default.
     *
     * @return self Resolved node identity
     * @throws ClusterConfigurationException When id or role is missing or the role is invalid
     * @throws EnvException When a node config env value cannot be read
     */
    public static function fromEnv(): self
    {
        $nodeId = trim(Hilos::$env[EnvConstants::CLUSTER_NODE_ID]);
        if ($nodeId === '') {
            throw ClusterConfigurationException::missingField(EnvConstants::CLUSTER_NODE_ID->name);
        }

        $roleValue = trim(Hilos::$env[EnvConstants::CLUSTER_NODE_ROLE]);
        if ($roleValue === '') {
            throw ClusterConfigurationException::missingField(EnvConstants::CLUSTER_NODE_ROLE->name);
        }

        $role = NodeRole::tryFrom($roleValue);
        if ($role === null) {
            throw ClusterConfigurationException::invalidRole($roleValue);
        }

        return new self($nodeId, $role, self::parseCapabilities(Hilos::$env[EnvConstants::CLUSTER_NODE_CAPABILITIES]));
    }

    /**
     * Reports whether this node declares the given capability tag.
     *
     * @param string $capability Capability tag to test
     * @return bool True when the tag is declared
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * Parses a comma-separated capability string into a normalized tag list.
     *
     * Blank entries are dropped and duplicates collapsed so the tag list is a
     * clean set regardless of how the operator spaced the configuration value.
     *
     * @param string $raw Raw comma-separated capability configuration value
     * @return list<string> Normalized capability tags
     */
    private static function parseCapabilities(string $raw): array
    {
        $tags = [];
        foreach (explode(',', $raw) as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
