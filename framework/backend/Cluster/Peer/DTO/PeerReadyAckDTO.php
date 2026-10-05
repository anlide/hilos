<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/** Acceptor's verdict on whether the candidate replaces the current route. */
final class PeerReadyAckDTO extends PeerDTO
{
    /** @var string Wire message type */
    public const string MESSAGE_TYPE = 'peer_ready_ack';

    /** @var string Whether the candidate becomes the working route */
    public const string FIELD_KEEP_NEW = 'keepNew';

    /** @param bool $keepNew Whether the candidate becomes the working route */
    public function __construct(public readonly bool $keepNew)
    {
    }

    /** @return string Message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Frame payload */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE, self::FIELD_KEEP_NEW => $this->keepNew];
    }

    /**
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the required field is missing or not a boolean
     */
    public static function fromArray(array $data): static
    {
        $value = $data[self::FIELD_KEEP_NEW] ?? null;
        if (!is_bool($value)) {
            throw new PeerTransportException('Peer ready ack requires a boolean keepNew');
        }

        return new static($value);
    }
}
