<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;

/** The answering node's wall clock in microseconds, correlated to one probe. */
final class PeerClockReplyDTO extends PeerDTO
{
    public const string MESSAGE_TYPE = 'peer_clock_reply';

    public const string FIELD_PROBE_ID = PeerClockProbeDTO::FIELD_PROBE_ID;

    public const string FIELD_NODE_TIME_US = 'nodeTimeUs';

    /**
     * @param string $probeId Identity echoed from the probe
     * @param int $nodeTimeUs Answering node's epoch microseconds
     */
    public function __construct(public readonly string $probeId, public readonly int $nodeTimeUs)
    {
    }

    /** @return string Clock-reply wire type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Clock-reply payload */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_PROBE_ID => $this->probeId,
            self::FIELD_NODE_TIME_US => $this->nodeTimeUs,
        ];
    }

    /**
     * @param array<string, mixed> $data Clock-reply payload
     * @return static Parsed clock reply
     * @throws PeerTransportException When either field is absent or mistyped, or the probe id is blank
     */
    public static function fromArray(array $data): static
    {
        try {
            $probeId = self::requireString($data, self::FIELD_PROBE_ID);
            $nodeTimeUs = self::requireInt($data, self::FIELD_NODE_TIME_US);
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException('Clock reply is malformed: ' . $exception->getMessage(), 0, $exception);
        }
        if (trim($probeId) === '') {
            throw new PeerTransportException('Clock reply carries no probe id.');
        }

        return new static($probeId, $nodeTimeUs);
    }
}
