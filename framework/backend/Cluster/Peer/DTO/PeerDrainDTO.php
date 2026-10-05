<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

/** End marker for application frames already queued on a replaced peer link. */
final class PeerDrainDTO extends PeerDTO
{
    /** @var string Wire message type */
    public const string MESSAGE_TYPE = 'peer_drain';

    /** @return string Message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Frame payload */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE];
    }

    /**
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
