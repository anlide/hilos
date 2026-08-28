<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;

/**
 * Every RT collection one node reads, told to the whole mesh.
 *
 * The node-level twin of {@see WorkerSourceInterestDTO}, and it exists for the same reason one
 * level up: a node knows what its own processes read, and the node holding a collection is the
 * one that decides whether a frame about it is worth a hop. Between them the two frames make the
 * map complete - which worker of which node needs which collection - without either side asking
 * the other a question.
 *
 * The list is the union of what this node's processes read and replaces whatever it announced
 * before, the way {@see WorkerSourceInterestDTO} replaces a worker's. It is not addressed: every
 * peer keeps the same map, so a node that becomes the owner of a collection later already knows
 * who was waiting for it.
 *
 * Both kinds are named (HIL-750). A DB row is read out of the shared database, so no node owes
 * another a copy of it - but a node that holds none of a collection has nothing to apply a frame
 * about it into, and telling the sender so is what stops the hop.
 */
final class PeerSourceInterestDTO extends PeerDTO
{
    /** @var string Wire message type for the reader-interest frame */
    public const string MESSAGE_TYPE = 'peer_source_interest';

    /** @var string Payload key: id of the node that reads these collections */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: RT collections that node reads */
    public const string FIELD_RT_COLLECTIONS = 'rtCollections';

    /** @var string Payload key: DB collections that node reads */
    public const string FIELD_DB_COLLECTIONS = 'dbCollections';

    /**
     * @param string $nodeId Id of the node that reads these collections
     * @param list<string> $rtCollections RT collections its processes read, each named once
     * @param list<string> $dbCollections DB collections its processes read, each named once
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly array $rtCollections,
        public readonly array $dbCollections,
    ) {
    }

    /**
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Serializes the interest frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_RT_COLLECTIONS => $this->rtCollections,
            self::FIELD_DB_COLLECTIONS => $this->dbCollections,
        ];
    }

    /**
     * Restores an interest frame from its wire array.
     *
     * A missing list reads as an empty one rather than as a malformed frame: a node running
     * nothing that reads reports exactly that, and so does a node of an older build that does not
     * know the field. What cannot be missing is the node id - a list with nobody behind it could
     * not be written into the map, and treating it as anonymous would let it replace somebody
     * else's ({@see PeerServer::onSourceInterestReceived()}).
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored interest frame
     * @throws PeerTransportException When the node id is missing
     */
    public static function fromArray(array $data): static
    {
        $nodeId = $data[self::FIELD_NODE_ID] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            throw new PeerTransportException('Peer source interest is missing the node id');
        }

        return new static(
            nodeId: $nodeId,
            rtCollections: self::collectionList($data, self::FIELD_RT_COLLECTIONS),
            dbCollections: self::collectionList($data, self::FIELD_DB_COLLECTIONS),
        );
    }

    /**
     * Reads one of the two collection lists out of the frame.
     *
     * Shared by both halves so neither can grow its own idea of what a malformed entry is: a
     * difference between them here would show up as one kind of frame quietly crossing the mesh
     * more widely than the other.
     *
     * @param array<string, mixed> $data Frame payload
     * @param string $field Payload key of the list to read
     * @return list<string> Collection keys named in that list, empty when it is absent
     */
    private static function collectionList(array $data, string $field): array
    {
        $collections = [];
        $raw = $data[$field] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $collectionKey) {
                if (is_string($collectionKey) && $collectionKey !== '') {
                    $collections[] = $collectionKey;
                }
            }
        }

        return $collections;
    }
}
