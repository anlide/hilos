<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
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
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $encoded = $data[self::PAYLOAD] ?? '';
        $encoded = is_string($encoded) ? $encoded : '';

        return new static(
            acceptKey: is_string($data[self::ACCEPT_KEY] ?? null) ? $data[self::ACCEPT_KEY] : '',
            payload: self::decodePayloadFromTransport($encoded),
        );
    }

    /**
     * Decode payload from JSON transport (strict base64) or pass through legacy raw string.
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
