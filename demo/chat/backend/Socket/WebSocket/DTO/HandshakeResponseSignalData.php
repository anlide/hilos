<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Exception\NotImplementedException;

/**
 * HandshakeResponseSignalData - Signal data for handshake response.
 *
 * {@see self::$frontend} must contain exactly the current authorized user under full.users;
 * the client reads the identity id from that payload. Display name, full chat snapshot
 * (users, bots, events), and session fields (moderation, file UI, upload progress)
 * are sent on page subscribe through browser rows.
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 */
final class HandshakeResponseSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates handshake response signal data.
     *
     * @param FrontendChangesDTO $frontend Frontend state payload (full.users with exactly the current user)
     * @param array<string, array<string, mixed>> $pageCatalog Page catalog for breadcrumb rendering
     */
    public function __construct(
        public readonly FrontendChangesDTO $frontend,
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
            'frontend' => $this->frontend->toArray(),
            'pageCatalog' => $this->pageCatalog,
        ];
    }

    /**
     * Create DTO from array (not implemented - response is created directly).
     *
     * @param array<string, mixed> $data Source data (ignored)
     * @return static DTO instance
     * @throws NotImplementedException Deserialization is not implemented
     */
    public static function fromArray(array $data): static
    {
        // This is not used for deserialization from array
        // Response is created directly in ChatAgent
        throw new NotImplementedException('HandshakeResponseSignalData::fromArray() is not implemented');
    }
}
