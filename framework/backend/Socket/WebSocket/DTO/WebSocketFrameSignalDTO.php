<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketFrameSignalDTO - DTO for WebSocket text frame signal.
 *
 * Represents a WebSocket text frame signal sent from WebSocket client.
 */
class WebSocketFrameSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAYLOAD = 'payload';

    /**
     * Creates text frame signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $payload Frame payload
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $payload,
    ) {
    }

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
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
     * The payload is required and an empty one passes through: a frame carrying
     * no bytes is a frame, while a frame carrying no payload key is truncated.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no accept key or no frame payload
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            payload: self::requireString($data, self::PAYLOAD),
        );
    }
}
