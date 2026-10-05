<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/** Dialer's readiness to use a certified peer link, including its old-route state. */
final class PeerReadyDTO extends PeerDTO
{
    /** @var string Wire message type */
    public const string MESSAGE_TYPE = 'peer_ready';

    /** @var string Whether the dialer still has a working route to this node */
    public const string FIELD_HAS_ACTIVE_LINK = 'hasActiveLink';

    /** @param bool $hasActiveLink Whether the dialer has a working old link */
    public function __construct(public readonly bool $hasActiveLink)
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
        return [self::TYPE => self::MESSAGE_TYPE, self::FIELD_HAS_ACTIVE_LINK => $this->hasActiveLink];
    }

    /**
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the required field is missing or not a boolean
     */
    public static function fromArray(array $data): static
    {
        $value = $data[self::FIELD_HAS_ACTIVE_LINK] ?? null;
        if (!is_bool($value)) {
            throw new PeerTransportException('Peer ready requires a boolean hasActiveLink');
        }

        return new static($value);
    }
}
