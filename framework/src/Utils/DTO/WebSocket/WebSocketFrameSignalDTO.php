<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO\WebSocket;

use Hilos\Utils\DTO\BaseDTO;
use Hilos\Utils\DTO\SignalDTO;

/**
 * WebSocketFrameSignalDTO - DTO for WebSocket text frame signal
 *
 * Represents a WebSocket text frame signal sent from WebSocket client.
 */
class WebSocketFrameSignalDTO extends BaseDTO implements SignalDTO
{
    // Field name constants
    public const string CLIENT_ID = 'clientId';
    public const string PAYLOAD = 'payload';

    public function __construct(
        public readonly string $clientId,
        public readonly string $payload,
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
            self::PAYLOAD => $this->payload,
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
            payload: $data[self::PAYLOAD] ?? '',
        );
    }
}

