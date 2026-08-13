<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketFrameBinarySignalDTO - DTO for WebSocket binary frame signal.
 *
 * Represents a WebSocket binary frame signal sent from WebSocket client.
 */
class WebSocketFrameBinarySignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
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

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * Converts DTO to array for transport.
     *
     * Payload is base64-encoded so JSON serialization (daemon log, worker IPC) never fails on binary bytes.
     *
     * @return array<string, string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::ACCEPT_KEY => $this->acceptKey,
            self::PAYLOAD => base64_encode($this->payload),
        ];
    }

    /**
     * Creates DTO from array.
     *
     * The payload is required and the empty string is a legal value of it: a
     * frame with no bytes is a frame, and {@see decodePayloadFromTransport()}
     * answers it as itself.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no accept key or no frame payload
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            payload: self::decodePayloadFromTransport(self::requireString($data, self::PAYLOAD)),
        );
    }

    /**
     * Decode payload from JSON transport (strict base64) or pass through legacy raw string.
     *
     * The blank answer is not an absent one: a frame with no bytes IS the empty
     * payload, so it is returned as itself rather than normalized to null.
     */
    private static function decodePayloadFromTransport(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        $decoded = base64_decode($encoded, true);

        return $decoded !== false ? $decoded : $encoded;
    }
}
