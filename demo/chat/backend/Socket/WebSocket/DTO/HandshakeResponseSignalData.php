<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * HandshakeResponseSignalData - Signal data for handshake response.
 *
 * Carries the current authorized user id and bootstrap page catalog. Display
 * name, chat snapshot, and session fields are sent through browser rows after
 * page subscription.
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 */
final class HandshakeResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public const string currentUser = 'self';
    public const string id = 'id';
    public const string pageCatalog = 'pageCatalog';

    /**
     * Creates handshake response signal data.
     *
     * @param int $selfId Current authorized user id
     * @param array<string, array<string, mixed>> $pageCatalog Page catalog for breadcrumb rendering
     */
    public function __construct(
        public readonly int $selfId,
        public readonly array $pageCatalog = [],
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::currentUser => [
                self::id => $this->selfId,
            ],
            self::pageCatalog => $this->pageCatalog,
        ];
    }

    /**
     * Create DTO from wire payload.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $self = $data[self::currentUser] ?? [];

        return new static(
            selfId: is_array($self) ? (int)($self[self::id] ?? 0) : 0,
            pageCatalog: is_array($data[self::pageCatalog] ?? null) ? $data[self::pageCatalog] : [],
        );
    }
}
