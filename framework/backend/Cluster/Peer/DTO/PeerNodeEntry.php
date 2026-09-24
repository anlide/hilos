<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerAddress;

/**
 * Wire form of one known cluster node inside a gossip frame.
 *
 * Carries what a node is made of — id, role, capabilities and advertised address —
 * and deliberately nothing about whether it is alive: a node takes liveness only
 * from its own link to the node it describes, so a neighbour has no liveness to
 * hand over (HIL-1059). The master-local `lastSeen` stays off the wire as well. It
 * bridges the wire and the domain: {@see fromNode()} serializes a
 * {@see ClusterNode}, {@see toIdentity()} reads it back as a {@see NodeIdentity}
 * the registry can merge.
 */
final class PeerNodeEntry extends BaseDTO
{
    /** @var string Payload key: node id */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: node role */
    public const string FIELD_NODE_ROLE = 'role';

    /** @var string Payload key: declared capability tags */
    public const string FIELD_NODE_CAPABILITIES = 'capabilities';

    /** @var string Payload key: advertised host:port address */
    public const string FIELD_ADDRESS = 'address';

    /**
     * @param string $nodeId Node id
     * @param NodeRole $role Node role
     * @param list<string> $capabilities Declared capability tags
     * @param ?PeerAddress $address Advertised address peers dial to reach the node
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly NodeRole $role,
        public readonly array $capabilities,
        public readonly ?PeerAddress $address,
    ) {
    }

    /**
     * Serializes a registry node into its wire entry.
     *
     * @param ClusterNode $node Registry node
     * @return self Wire entry
     */
    public static function fromNode(ClusterNode $node): self
    {
        return new self($node->nodeId, $node->role, $node->capabilities, $node->address);
    }

    /**
     * Builds a wire entry from an identity.
     *
     * @param NodeIdentity $identity Node identity
     * @return self Wire entry
     */
    public static function fromIdentity(NodeIdentity $identity): self
    {
        return new self($identity->nodeId, $identity->role, $identity->capabilities, $identity->address);
    }

    /**
     * Reads this wire entry back as a node identity for the registry to merge.
     *
     * @return NodeIdentity Node identity
     */
    public function toIdentity(): NodeIdentity
    {
        return NodeIdentity::of($this->nodeId, $this->role, $this->capabilities, $this->address);
    }

    /**
     * Serializes the entry to its wire array.
     *
     * @return array<string, mixed> Entry payload
     */
    public function toArray(): array
    {
        return [
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_NODE_ROLE => $this->role->value,
            self::FIELD_NODE_CAPABILITIES => $this->capabilities,
            self::FIELD_ADDRESS => $this->address?->toString(),
        ];
    }

    /**
     * Restores an entry from its wire array.
     *
     * @param array<string, mixed> $data Entry payload
     * @return static Restored entry
     * @throws PeerTransportException When the node id is missing or the role is invalid
     */
    public static function fromArray(array $data): static
    {
        $nodeIdValue = $data[self::FIELD_NODE_ID] ?? null;
        $nodeId = is_string($nodeIdValue) ? trim($nodeIdValue) : null;
        if ($nodeId === null || $nodeId === '') {
            throw new PeerTransportException('Peer node entry is missing the node id');
        }

        $roleValue = $data[self::FIELD_NODE_ROLE] ?? null;
        $role = is_string($roleValue) ? NodeRole::tryFrom($roleValue) : null;
        if ($role === null) {
            $shownRole = is_string($roleValue) ? $roleValue : get_debug_type($roleValue);
            throw new PeerTransportException("Peer node entry has an invalid node role '{$shownRole}'");
        }

        $address = $data[self::FIELD_ADDRESS] ?? null;

        return new static(
            nodeId: $nodeId,
            role: $role,
            capabilities: PeerDTO::normalizeCapabilities($data[self::FIELD_NODE_CAPABILITIES] ?? []),
            address: is_string($address) ? PeerAddress::fromString($address) : null,
        );
    }
}
