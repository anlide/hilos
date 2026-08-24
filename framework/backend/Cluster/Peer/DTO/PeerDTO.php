<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Thin envelope base for every framed message on the peer channel.
 *
 * A peer frame is newline-delimited JSON tagged with a `type`; this base only
 * knows how to name that type and dispatch a raw frame to the right concrete
 * DTO. The handshake frames share a payload shape via {@see PeerHandshakeDTO};
 * the membership-gossip frames ({@see PeerRosterDTO}, {@see PeerAnnounceDTO})
 * carry node entries; the consensus frames ({@see PeerRequestVoteDTO},
 * {@see PeerVoteReplyDTO}, {@see PeerHeartbeatDTO}) carry election terms; the
 * graceful-leave frame ({@see PeerNodeLeavingDTO}) announces a planned departure;
 * the placement frames ({@see PeerPlaceAgentDTO}, {@see PeerStopAgentDTO},
 * {@see PeerAgentStatusDTO}, {@see PeerPlacementQueryDTO}, {@see PeerPlacementReportDTO},
 * {@see PeerPlacementViewDTO}) launch, stop, and track agents placed on a named node, the last
 * of them handing the leader's whole picture back down so any node can tell where an agent
 * runs; the signal-forward frame
 * ({@see PeerSignalDTO}) carries one resolved signal to an agent on another node; the
 * RT replication frames ({@see PeerRtSyncDTO}, {@see PeerRtSnapshotDTO}) carry one RT sync
 * fact, and one whole collection to a node that just joined, from the node that owns it to
 * the other nodes' read-only copies; the connection-index frames
 * ({@see PeerConnectionsSnapshotDTO}, {@see PeerConnectionsDeltaDTO}) tell the mesh which
 * browser connections each node holds, so a signal can be addressed to the node a browser is
 * attached to, and the client-forward frame ({@see PeerClientSignalDTO}) is what that
 * addressing then sends; the fan-out frame ({@see PeerClientFanoutDTO}) is what the same
 * index cannot help with, since which browsers a fan-out reaches is answered by each node's
 * own subscription registry rather than by an address; the
 * protected-mode frames ({@see PeerProtectedModeEnableDTO}, {@see PeerProtectedModeReadyDTO},
 * {@see PeerProtectedModeDisableDTO}) carry the initiator↔leader freeze hand-off that the
 * agent-signal fabric cannot deliver to a leader daemon, and their cluster-wide mirror
 * ({@see PeerProtectedModeQuiesceDTO}, {@see PeerProtectedModeQuiescedDTO},
 * {@see PeerProtectedModeLiftDTO}) carries the leader↔follower freeze the leader drives; the
 * liveness frames
 * ({@see PeerPingDTO}, {@see PeerPongDTO}) keep a quiet link proven alive.
 * All extend this base directly.
 */
abstract class PeerDTO extends BaseDTO
{
    /** @var string Envelope key naming the message type */
    public const string TYPE = 'type';

    /**
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    abstract public function getType(): string;

    /**
     * Parses a newline-delimited JSON peer frame into its concrete DTO.
     *
     * @param string $json One JSON frame from the peer channel
     * @return self Parsed frame
     * @throws PeerTransportException When the frame is not a JSON object or carries an unknown type
     */
    public static function fromWire(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new PeerTransportException('Peer frame is not a JSON object');
        }

        $typeValue = $data[self::TYPE] ?? null;
        $type = is_string($typeValue) ? $typeValue : null;

        return match ($type) {
            PeerHelloDTO::MESSAGE_TYPE => PeerHelloDTO::fromArray($data),
            PeerWelcomeDTO::MESSAGE_TYPE => PeerWelcomeDTO::fromArray($data),
            PeerRosterDTO::MESSAGE_TYPE => PeerRosterDTO::fromArray($data),
            PeerAnnounceDTO::MESSAGE_TYPE => PeerAnnounceDTO::fromArray($data),
            PeerRequestVoteDTO::MESSAGE_TYPE => PeerRequestVoteDTO::fromArray($data),
            PeerVoteReplyDTO::MESSAGE_TYPE => PeerVoteReplyDTO::fromArray($data),
            PeerHeartbeatDTO::MESSAGE_TYPE => PeerHeartbeatDTO::fromArray($data),
            PeerNodeLeavingDTO::MESSAGE_TYPE => PeerNodeLeavingDTO::fromArray($data),
            PeerPlaceAgentDTO::MESSAGE_TYPE => PeerPlaceAgentDTO::fromArray($data),
            PeerStopAgentDTO::MESSAGE_TYPE => PeerStopAgentDTO::fromArray($data),
            PeerAgentStatusDTO::MESSAGE_TYPE => PeerAgentStatusDTO::fromArray($data),
            PeerPlacementQueryDTO::MESSAGE_TYPE => PeerPlacementQueryDTO::fromArray($data),
            PeerPlacementReportDTO::MESSAGE_TYPE => PeerPlacementReportDTO::fromArray($data),
            PeerPlacementViewDTO::MESSAGE_TYPE => PeerPlacementViewDTO::fromArray($data),
            PeerSignalDTO::MESSAGE_TYPE => PeerSignalDTO::fromArray($data),
            PeerRtSyncDTO::MESSAGE_TYPE => PeerRtSyncDTO::fromArray($data),
            PeerRtSnapshotDTO::MESSAGE_TYPE => PeerRtSnapshotDTO::fromArray($data),
            PeerClientSignalDTO::MESSAGE_TYPE => PeerClientSignalDTO::fromArray($data),
            PeerClientFanoutDTO::MESSAGE_TYPE => PeerClientFanoutDTO::fromArray($data),
            PeerConnectionsSnapshotDTO::MESSAGE_TYPE => PeerConnectionsSnapshotDTO::fromArray($data),
            PeerConnectionsDeltaDTO::MESSAGE_TYPE => PeerConnectionsDeltaDTO::fromArray($data),
            PeerProtectedModeEnableDTO::MESSAGE_TYPE => PeerProtectedModeEnableDTO::fromArray($data),
            PeerProtectedModeReadyDTO::MESSAGE_TYPE => PeerProtectedModeReadyDTO::fromArray($data),
            PeerProtectedModeDisableDTO::MESSAGE_TYPE => PeerProtectedModeDisableDTO::fromArray($data),
            PeerProtectedModeQuiesceDTO::MESSAGE_TYPE => PeerProtectedModeQuiesceDTO::fromArray($data),
            PeerProtectedModeQuiescedDTO::MESSAGE_TYPE => PeerProtectedModeQuiescedDTO::fromArray($data),
            PeerDbReHydrateDTO::MESSAGE_TYPE => PeerDbReHydrateDTO::fromArray($data),
            PeerDbReHydratedDTO::MESSAGE_TYPE => PeerDbReHydratedDTO::fromArray($data),
            PeerProtectedModeLiftDTO::MESSAGE_TYPE => PeerProtectedModeLiftDTO::fromArray($data),
            PeerProtectedModeVerifyDTO::MESSAGE_TYPE => PeerProtectedModeVerifyDTO::fromArray($data),
            PeerProtectedModeProgressDTO::MESSAGE_TYPE => PeerProtectedModeProgressDTO::fromArray($data),
            PeerProtectedModePassDTO::MESSAGE_TYPE => PeerProtectedModePassDTO::fromArray($data),
            PeerProtectedModeRefreezeDTO::MESSAGE_TYPE => PeerProtectedModeRefreezeDTO::fromArray($data),
            PeerPingDTO::MESSAGE_TYPE => PeerPingDTO::fromArray($data),
            PeerPongDTO::MESSAGE_TYPE => PeerPongDTO::fromArray($data),
            default => throw new PeerTransportException(
                "Unknown peer frame type: '" . ($type ?? get_debug_type($typeValue)) . "'",
            ),
        };
    }

    /**
     * Reads one accept-key list out of a connection-index frame.
     *
     * Shared by {@see PeerConnectionsSnapshotDTO} and {@see PeerConnectionsDeltaDTO}, the
     * latter carrying two such lists. Strict where {@see normalizeCapabilities()} is lenient,
     * and for a reason: a capability tag nobody understands is noise, but an accept key is the
     * address a signal is delivered to, so a blank or non-string entry would index a
     * connection nothing can ever reach. Such a frame is refused whole rather than thinned.
     *
     * @param array<string, mixed> $data Frame payload
     * @param string $field Payload key holding the list
     * @param string $frameName How the frame names itself in the failure message
     * @return list<string> Accept keys as read from the wire
     * @throws PeerTransportException When the field is absent or holds anything but accept keys
     */
    public static function readAcceptKeys(array $data, string $field, string $frameName): array
    {
        $raw = $data[$field] ?? null;
        if (!is_array($raw)) {
            throw new PeerTransportException("Peer connections {$frameName} is missing the '{$field}' list");
        }

        $acceptKeys = [];
        foreach ($raw as $acceptKey) {
            if (!is_string($acceptKey) || $acceptKey === '') {
                throw new PeerTransportException("Peer connections {$frameName} carries a malformed accept key");
            }

            $acceptKeys[] = $acceptKey;
        }

        return $acceptKeys;
    }

    /**
     * Normalizes a wire capabilities value into a clean list of string tags.
     *
     * Shared by every frame that carries a node's capabilities, so blank and
     * non-string entries are dropped consistently.
     *
     * @param mixed $raw Raw capabilities value from the wire
     * @return list<string> Capability tags
     */
    public static function normalizeCapabilities(mixed $raw): array
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
