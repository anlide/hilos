<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerAddress;

/**
 * Base for the framed handshake messages exchanged over a peer channel.
 *
 * Hello and welcome share one payload shape — the sender's self-declared
 * identity plus the protocol version — and differ only by message type, so the
 * array shape and its parsing live here and the concrete classes carry just the
 * type. Frames are newline-delimited JSON, matching the worker and command
 * channels.
 */
abstract class PeerDTO extends BaseDTO
{
    /** @var string Envelope key naming the message type */
    public const string TYPE = 'type';

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
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    abstract public function getType(): string;

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
        $nodeId = trim((string)($data[self::FIELD_NODE_ID] ?? ''));
        if ($nodeId === '') {
            throw new PeerTransportException('Peer handshake is missing the node id');
        }

        $roleValue = (string)($data[self::FIELD_NODE_ROLE] ?? '');
        $role = NodeRole::tryFrom($roleValue);
        if ($role === null) {
            throw new PeerTransportException("Peer handshake has an invalid node role '{$roleValue}'");
        }

        return new static(
            protocolVersion: (int)($data[self::FIELD_PROTOCOL_VERSION] ?? 0),
            nodeId: $nodeId,
            role: $role,
            capabilities: self::parseCapabilities($data[self::FIELD_NODE_CAPABILITIES] ?? []),
            address: PeerAddress::fromString((string)($data[self::FIELD_ADDRESS] ?? '')),
        );
    }

    /**
     * Parses a newline-delimited JSON peer frame into its concrete DTO.
     *
     * @param string $json One JSON frame from the peer channel
     * @return self Parsed handshake frame
     * @throws PeerTransportException When the frame is not a JSON object or carries an unknown type
     */
    public static function fromWire(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new PeerTransportException('Peer frame is not a JSON object');
        }

        $type = (string)($data[self::TYPE] ?? '');

        return match ($type) {
            PeerHelloDTO::MESSAGE_TYPE => PeerHelloDTO::fromArray($data),
            PeerWelcomeDTO::MESSAGE_TYPE => PeerWelcomeDTO::fromArray($data),
            default => throw new PeerTransportException("Unknown peer frame type: '{$type}'"),
        };
    }

    /**
     * Normalizes the wire capabilities value into a clean list of string tags.
     *
     * @param mixed $raw Raw capabilities value from the wire
     * @return list<string> Capability tags
     */
    private static function parseCapabilities(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
