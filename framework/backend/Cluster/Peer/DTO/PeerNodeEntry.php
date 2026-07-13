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
 * Carries the fields peers exchange about a node — id, role, capabilities,
 * advertised address, and online flag — without the master-local `lastSeen`. It
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

    /** @var string Payload key: whether the node is online */
    public const string FIELD_ONLINE = 'online';

    /**
     * @param string $nodeId Node id
     * @param NodeRole $role Node role
     * @param list<string> $capabilities Declared capability tags
     * @param ?PeerAddress $address Advertised address peers dial to reach the node
     * @param bool $online Whether the node is online
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly NodeRole $role,
        public readonly array $capabilities,
        public readonly ?PeerAddress $address,
        public readonly bool $online,
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
        return new self($node->nodeId, $node->role, $node->capabilities, $node->address, $node->online);
    }

    /**
     * Builds a wire entry from an identity plus an online flag.
     *
     * @param NodeIdentity $identity Node identity
     * @param bool $online Whether the node is online
     * @return self Wire entry
     */
    public static function fromIdentity(NodeIdentity $identity, bool $online): self
    {
        return new self($identity->nodeId, $identity->role, $identity->capabilities, $identity->address, $online);
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
            self::FIELD_ONLINE => $this->online,
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
        $nodeId = trim((string)($data[self::FIELD_NODE_ID] ?? ''));
        if ($nodeId === '') {
            throw new PeerTransportException('Peer node entry is missing the node id');
        }

        $roleValue = (string)($data[self::FIELD_NODE_ROLE] ?? '');
        $role = NodeRole::tryFrom($roleValue);
        if ($role === null) {
            throw new PeerTransportException("Peer node entry has an invalid node role '{$roleValue}'");
        }

        return new static(
            nodeId: $nodeId,
            role: $role,
            capabilities: PeerDTO::normalizeCapabilities($data[self::FIELD_NODE_CAPABILITIES] ?? []),
            address: PeerAddress::fromString((string)($data[self::FIELD_ADDRESS] ?? '')),
            online: (bool)($data[self::FIELD_ONLINE] ?? false),
        );
    }
}
