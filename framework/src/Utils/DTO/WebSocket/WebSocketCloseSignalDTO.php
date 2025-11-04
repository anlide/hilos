<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO\WebSocket;

use Hilos\Utils\DTO\BaseDTO;

/**
 * WebSocketCloseSignalDTO - DTO for WebSocket close signal
 *
 * Represents a WebSocket close signal sent from WebSocket client.
 */
class WebSocketCloseSignalDTO extends BaseDTO
{
    // Field name constants
    public const string CLIENT_ID = 'clientId';

    public function __construct(
        public readonly string $clientId,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::CLIENT_ID => $this->clientId,
        ];
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            clientId: $data[self::CLIENT_ID] ?? '',
        );
    }
}
