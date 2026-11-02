<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;

/** A correlated clock question, accepted only on a handshaked link. */
final class PeerClockProbeDTO extends PeerDTO
{
    public const string MESSAGE_TYPE = 'peer_clock_probe';

    public const string FIELD_PROBE_ID = 'probeId';

    /** @param string $probeId Identity of one exchange in a measurement series */
    public function __construct(public readonly string $probeId)
    {
    }

    /** @return string Clock-probe wire type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Clock-probe payload */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE, self::FIELD_PROBE_ID => $this->probeId];
    }

    /**
     * @param array<string, mixed> $data Clock-probe payload
     * @return static Parsed clock probe
     * @throws PeerTransportException When the probe id is absent, not a string, or blank
     */
    public static function fromArray(array $data): static
    {
        try {
            $probeId = self::requireString($data, self::FIELD_PROBE_ID);
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException('Clock probe is malformed: ' . $exception->getMessage(), 0, $exception);
        }
        if (trim($probeId) === '') {
            throw new PeerTransportException('Clock probe carries no probe id.');
        }

        return new static($probeId);
    }
}
