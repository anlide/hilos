<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketFrameBinarySignalDTO - DTO for WebSocket binary frame signal
 *
 * Represents a WebSocket binary frame signal sent from WebSocket client.
 */
class WebSocketFrameBinarySignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAYLOAD = 'payload';

    /**
     * Creates binary frame signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $payload Frame payload (base64 or raw)
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $payload,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::ACCEPT_KEY => $this->acceptKey,
            self::PAYLOAD => $this->payload,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            payload: $data[self::PAYLOAD] ?? '',
        );
    }
}
