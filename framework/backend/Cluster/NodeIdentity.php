<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Placement\NodeCapacities;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Immutable self-declared identity of the local cluster node.
 *
 * Identity is "who am I", not "who else is in the cluster": it carries the node's
 * own id, role, declared capability tags, and the address peers dial to reach it.
 * Membership (the live set of peers) is a separate runtime registry built by
 * peer-join and is not modelled here. Capabilities stay a flat tag list on the
 * wire; HIL-182 layers the hard/soft matching on top by reading numeric
 * capacities out of the tags ({@see NodeCapacities}) without changing this
 * contract. The coordinator-preference model remains a later slice.
 */
final class NodeIdentity
{
    /**
     * @param string $nodeId Unique self-declared node id
     * @param NodeRole $role Self-declared node role
     * @param list<string> $capabilities Declared capability tags
     * @param ?PeerAddress $address Advertised address peers dial to reach this node, or null when none is configured
     */
    private function __construct(
        public readonly string $nodeId,
        public readonly NodeRole $role,
        public readonly array $capabilities,
        public readonly ?PeerAddress $address,
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

        return new self(
            $nodeId,
            $role,
            self::parseCapabilities(Hilos::$env[EnvConstants::CLUSTER_NODE_CAPABILITIES]),
            self::resolveAdvertiseAddress(),
        );
    }

    /**
     * Resolves the address peers dial to reach this node.
     *
     * Prefers the explicit CLUSTER_PEER_ADVERTISE value; when it is empty or
     * malformed, falls back to the peer bind host:port (which is only reachable
     * when the bind host is a concrete address, not a wildcard).
     *
     * @return ?PeerAddress Advertised address, or null when none can be resolved
     * @throws EnvException When a peer address env value cannot be read
     */
    private static function resolveAdvertiseAddress(): ?PeerAddress
    {
        $advertised = PeerAddress::fromString(Hilos::$env[EnvConstants::CLUSTER_PEER_ADVERTISE]);
        if ($advertised !== null) {
            return $advertised;
        }

        $host = trim(Hilos::$env[EnvConstants::CLUSTER_PEER_HOST]);
        $port = Hilos::$env->int(EnvConstants::CLUSTER_PEER_PORT);

        return $host !== '' && $port > 0 ? new PeerAddress($host, $port) : null;
    }

    /**
     * Builds an identity for a known node from explicit values.
     *
     * Used for a remote peer whose identity arrived over the wire, as opposed to
     * {@see fromEnv()} which resolves the local node from configuration.
     *
     * @param string $nodeId Node id
     * @param NodeRole $role Node role
     * @param list<string> $capabilities Declared capability tags
     * @param ?PeerAddress $address Advertised address peers dial to reach the node
     * @return self Node identity
     */
    public static function of(string $nodeId, NodeRole $role, array $capabilities, ?PeerAddress $address = null): self
    {
        return new self($nodeId, $role, $capabilities, $address);
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
