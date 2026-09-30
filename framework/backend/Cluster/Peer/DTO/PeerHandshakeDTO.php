<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerMarkers;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Base for the framed handshake messages (hello and welcome).
 *
 * Both carry the same payload — the sender's self-declared identity (id, role,
 * capabilities, advertised address), the protocol version and the sender's markers
 * ({@see PeerMarkers}: what it reads that every node must share, one value per kind) — and
 * differ only by message type, so the shape and its parsing live here and the concrete
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

    /** @var string Payload key: sender markers, one value per kind */
    public const string FIELD_MARKERS = 'markers';

    /**
     * @param int $protocolVersion Sender peer wire-protocol version
     * @param string $nodeId Sender self-declared node id
     * @param NodeRole $role Sender self-declared role
     * @param list<string> $capabilities Sender declared capability tags
     * @param array<string, string> $markers Sender markers, one value per kind ({@see PeerMarkers})
     * @param ?PeerAddress $address Sender advertised address, or null when none is advertised
     */
    public function __construct(
        public readonly int $protocolVersion,
        public readonly string $nodeId,
        public readonly NodeRole $role,
        public readonly array $capabilities,
        public readonly array $markers,
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
            self::FIELD_MARKERS => $this->markers,
        ];
    }

    /**
     * Restores a concrete handshake frame from its wire array.
     *
     * Presence and type are answered by the payload helpers, and their refusal is
     * re-thrown as the one exception this transport speaks - {@see PeerLink} drops
     * the link on it and on nothing else. What stays a check of its own is what the
     * helpers cannot answer: an id that arrived blank, a role that arrived as a
     * name no {@see NodeRole} carries, and a marker whose kind or value is not a
     * non-empty string. The advertised address is the one field a node may
     * legitimately not have, and its own toArray() writes null for it; the markers
     * are required, an empty set included - a node that carries none says so.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored handshake frame
     * @throws PeerTransportException When a field is missing, the node id is blank, the role is invalid
     *                                or a marker is malformed
     */
    public static function fromArray(array $data): static
    {
        try {
            $protocolVersion = self::requireInt($data, self::FIELD_PROTOCOL_VERSION);
            $nodeId = trim(self::requireString($data, self::FIELD_NODE_ID));
            $roleValue = self::requireString($data, self::FIELD_NODE_ROLE);
            $capabilities = self::requireArray($data, self::FIELD_NODE_CAPABILITIES);
            $address = self::optionalString($data, self::FIELD_ADDRESS);
            $markers = self::requireArray($data, self::FIELD_MARKERS);
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

        foreach ($markers as $kind => $marker) {
            if (!is_string($kind) || $kind === '' || !is_string($marker) || $marker === '') {
                throw new PeerTransportException('Peer handshake carries a malformed marker');
            }
        }

        return new static(
            protocolVersion: $protocolVersion,
            nodeId: $nodeId,
            role: $role,
            capabilities: PeerDTO::normalizeCapabilities($capabilities),
            markers: $markers,
            address: $address === null ? null : PeerAddress::fromString($address),
        );
    }
}
