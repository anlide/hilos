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
 * {@see PeerAgentStatusDTO}, {@see PeerPlacementQueryDTO}, {@see PeerPlacementReportDTO})
 * launch, stop, and track agents placed on a named node; the signal-forward frame
 * ({@see PeerSignalDTO}) carries one resolved signal to an agent on another node; the
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

        $type = (string)($data[self::TYPE] ?? '');

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
            PeerSignalDTO::MESSAGE_TYPE => PeerSignalDTO::fromArray($data),
            PeerProtectedModeEnableDTO::MESSAGE_TYPE => PeerProtectedModeEnableDTO::fromArray($data),
            PeerProtectedModeReadyDTO::MESSAGE_TYPE => PeerProtectedModeReadyDTO::fromArray($data),
            PeerProtectedModeDisableDTO::MESSAGE_TYPE => PeerProtectedModeDisableDTO::fromArray($data),
            PeerProtectedModeQuiesceDTO::MESSAGE_TYPE => PeerProtectedModeQuiesceDTO::fromArray($data),
            PeerProtectedModeQuiescedDTO::MESSAGE_TYPE => PeerProtectedModeQuiescedDTO::fromArray($data),
            PeerProtectedModeLiftDTO::MESSAGE_TYPE => PeerProtectedModeLiftDTO::fromArray($data),
            PeerPingDTO::MESSAGE_TYPE => PeerPingDTO::fromArray($data),
            PeerPongDTO::MESSAGE_TYPE => PeerPongDTO::fromArray($data),
            default => throw new PeerTransportException("Unknown peer frame type: '{$type}'"),
        };
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
