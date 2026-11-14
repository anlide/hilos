<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Stream\RtSyncStreamFrame;

/**
 * One frame of the ordinary RT stream on a peer link, in either direction: a part of an
 * operation written on the sending node, or the answer to one ({@see RtSyncStreamFrame}).
 *
 * The operation names the node it was written on; the receiver applies it only when that is the
 * node the link was handshaked with, and never announces it on - one hop, as before.
 */
final class PeerRtSyncStreamDTO extends PeerDTO
{
    /** @var string Wire message type for an RT stream frame */
    public const string MESSAGE_TYPE = 'peer_rt_sync_stream';

    /**
     * @param RtSyncStreamFrame $frame Part or answer
     */
    public function __construct(public readonly RtSyncStreamFrame $frame)
    {
    }

    /** @return string Message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Frame fields under the message type */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE] + $this->frame->toArray();
    }

    /**
     * @param array<string, mixed> $data Frame fields under the message type
     * @return static Stream frame
     * @throws PeerTransportException When the frame is malformed or outside its domain
     */
    public static function fromArray(array $data): static
    {
        try {
            return new static(RtSyncStreamFrame::fromArray($data));
        } catch (InvalidFormatException $malformed) {
            throw new PeerTransportException('Peer RT stream frame is malformed: ' . $malformed->getMessage(), previous: $malformed);
        }
    }
}
