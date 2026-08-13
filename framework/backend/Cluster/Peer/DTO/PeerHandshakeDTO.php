<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Core\Exception\InvalidFormatException;

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
     * Presence and type are answered by the payload helpers, and their refusal is
     * re-thrown as the one exception this transport speaks - {@see PeerLink} drops
     * the link on it and on nothing else. What stays a check of its own is what the
     * helpers cannot answer: an id that arrived blank, and a role that arrived as a
     * name no {@see NodeRole} carries. The advertised address is the one field a
     * node may legitimately not have, and its own toArray() writes null for it.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored handshake frame
     * @throws PeerTransportException When a field is missing, the node id is blank or the role is invalid
     */
    public static function fromArray(array $data): static
    {
        try {
            $protocolVersion = self::requireInt($data, self::FIELD_PROTOCOL_VERSION);
            $nodeId = trim(self::requireString($data, self::FIELD_NODE_ID));
            $roleValue = self::requireString($data, self::FIELD_NODE_ROLE);
            $capabilities = self::requireArray($data, self::FIELD_NODE_CAPABILITIES);
            $address = self::optionalString($data, self::FIELD_ADDRESS);
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException('Peer handshake is malformed: ' . $exception->getMessage(), 0, $exception);
        }

        if ($nodeId === '') {
            throw new PeerTransportException('Peer handshake is missing the node id');
        }

        $role = NodeRole::tryFrom($roleValue);
        if ($role === null) {
            throw new PeerTransportException("Peer handshake has an invalid node role '{$roleValue}'");
        }

        return new static(
            protocolVersion: $protocolVersion,
            nodeId: $nodeId,
            role: $role,
            capabilities: PeerDTO::normalizeCapabilities($capabilities),
            address: $address === null ? null : PeerAddress::fromString($address),
        );
    }
}
