<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Database\ReHydrateRound;

/**
 * Peer frame a node sends back once it has finished re-reading a replaced database.
 *
 * The reply to {@see PeerDbReHydrateDTO}: the node ran its own local {@see ReHydrateRound} over
 * its daemon and its workers, and reports the whole node's verdict in one frame with
 * {@see PeerServer::sendToMaster}. The initiator waits for nodes, not for their workers - the
 * roster of a node is known only to that node.
 *
 * Unlike its quiesce-round sibling it does carry a payload, and every field earns its place: the
 * answer can be negative, and when it is, the operator has to be told which processes on which
 * node did not come back - and the number the announcement arrived under comes back untouched, so
 * that an answer to a restore that has since been superseded is not credited to the current one
 * (HIL-694).
 */
final class PeerDbReHydratedDTO extends PeerDTO
{
    /** @var string Wire message type for the database re-hydrated report */
    public const string MESSAGE_TYPE = 'peer_db_rehydrated';

    /** @var string Field key carrying the answering node id */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Field key carrying the announcing node's round number, as it arrived */
    public const string FIELD_ROUND = 'round';

    /** @var string Field key carrying whether that node's barrier closed */
    public const string FIELD_OK = 'ok';

    /** @var string Field key carrying the node's own problem lines */
    public const string FIELD_PROBLEMS = 'problems';

    /**
     * @param string $nodeId Node answering for itself and its workers
     * @param int $round Number the announcement carried, returned to the node that opened that round
     * @param bool $ok Whether every process on that node re-read successfully
     * @param list<string> $problems That node's own problem lines, empty when it came back whole
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly int $round,
        public readonly bool $ok,
        public readonly array $problems = [],
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
     * Serializes the re-hydrated report to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_ROUND => $this->round,
            self::FIELD_OK => $this->ok,
            self::FIELD_PROBLEMS => $this->problems,
        ];
    }

    /**
     * Restores a re-hydrated report from its wire array.
     *
     * A report whose verdict did not survive the wire is read as a failure: the barrier it feeds
     * is fail-closed, and an unreadable answer is not a confirmation. A report with no node on it
     * is refused outright rather than read as an answer from nobody - the initiator credits the
     * answer to a named participant, and a nameless one would be dropped as a stranger anyway. A
     * report with no round on it is refused for the same reason and just as strictly: without the
     * number the answer names no question, and reading it as 0 would only hide that.
     *
     * @param array<string, mixed> $data Frame payload (nodeId, round, ok, problems)
     * @return static Restored frame
     * @throws PeerTransportException When the report names no answering node or no round
     */
    public static function fromArray(array $data): static
    {
        $nodeId = $data[self::FIELD_NODE_ID] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            throw new PeerTransportException('Peer db re-hydrated report names no answering node');
        }

        $round = $data[self::FIELD_ROUND] ?? null;
        if (!is_int($round) || $round < 1) {
            throw new PeerTransportException('Peer db re-hydrated report carries no round number');
        }

        $problems = $data[self::FIELD_PROBLEMS] ?? [];

        return new static(
            nodeId: $nodeId,
            round: $round,
            ok: (bool)($data[self::FIELD_OK] ?? false),
            problems: is_array($problems) ? array_values(array_map(strval(...), $problems)) : [],
        );
    }
}
