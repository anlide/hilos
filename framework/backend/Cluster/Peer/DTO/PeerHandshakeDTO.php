<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerAddress;

/**
 * Base for the framed handshake messages (hello and welcome).
 *
 * Both carry the same payload — the sender's self-declared identity (id, role,
 * capabilities, advertised address) plus the protocol version — and differ only
 * by message type, so the shape and its parsing live here and the concrete
 * classes carry just the type.
 */
abstract class PeerHandshakeDTO extends PeerDTO
{
    /** @var string Payload key: peer wire-protocol version */
    public const string FIELD_PROTOCOL_VERSION = 'protocolVersion';

    /** @var string Payload key: sender node id */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: sender node role */
    public const string FIELD_NODE_ROLE = 'role';

    /** @var string Payload key: sender declared capability tags */
    public const string FIELD_NODE_CAPABILITIES = 'capabilities';

    /** @var string Payload key: sender advertised host:port address */
    public const string FIELD_ADDRESS = 'address';

    /**
     * @param int $protocolVersion Sender peer wire-protocol version
     * @param string $nodeId Sender self-declared node id
     * @param NodeRole $role Sender self-declared role
     * @param list<string> $capabilities Sender declared capability tags
     * @param ?PeerAddress $address Sender advertised address, or null when none is advertised
     */
    public function __construct(
        public readonly int $protocolVersion,
        public readonly string $nodeId,
        public readonly NodeRole $role,
        public readonly array $capabilities,
        public readonly ?PeerAddress $address = null,
    ) {
    }

    /**
     * Serializes the handshake frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => $this->getType(),
            self::FIELD_PROTOCOL_VERSION => $this->protocolVersion,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_NODE_ROLE => $this->role->value,
            self::FIELD_NODE_CAPABILITIES => $this->capabilities,
            self::FIELD_ADDRESS => $this->address?->toString(),
        ];
    }

    /**
     * Restores a concrete handshake frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored handshake frame
     * @throws PeerTransportException When the node id is missing or the role is invalid
     */
    public static function fromArray(array $data): static
    {
        $nodeIdValue = $data[self::FIELD_NODE_ID] ?? null;
        $nodeId = is_string($nodeIdValue) ? trim($nodeIdValue) : null;
        if ($nodeId === null || $nodeId === '') {
            throw new PeerTransportException('Peer handshake is missing the node id');
        }

        $roleValue = $data[self::FIELD_NODE_ROLE] ?? null;
        $role = is_string($roleValue) ? NodeRole::tryFrom($roleValue) : null;
        if ($role === null) {
            $shownRole = is_string($roleValue) ? $roleValue : get_debug_type($roleValue);
            throw new PeerTransportException("Peer handshake has an invalid node role '{$shownRole}'");
        }

        $address = $data[self::FIELD_ADDRESS] ?? null;

        return new static(
            protocolVersion: (int)($data[self::FIELD_PROTOCOL_VERSION] ?? 0),
            nodeId: $nodeId,
            role: $role,
            capabilities: PeerDTO::normalizeCapabilities($data[self::FIELD_NODE_CAPABILITIES] ?? []),
            address: is_string($address) ? PeerAddress::fromString($address) : null,
        );
    }
}
