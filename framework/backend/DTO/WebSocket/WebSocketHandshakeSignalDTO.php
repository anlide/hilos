<?php

declare(strict_types=1);

namespace Hilos\DTO\WebSocket;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;
use Hilos\DTO\SignalDataDTO;

/**
 * WebSocketHandshakeSignalDTO - DTO for WebSocket handshake signal
 *
 * Represents a WebSocket handshake signal sent from WebSocket client.
 */
class WebSocketHandshakeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string HEADERS = 'headers';
    public const string ACCEPT_KEY = 'acceptKey';
    public const string COOKIES = 'cookies';
    public const string CLIENT_IP = 'clientIp';
    public const string QUERY_PARAMS = 'queryParams';

    public function __construct(
        public readonly array $headers,
        public readonly string $acceptKey,
        public readonly array $cookies,
        public readonly string $clientIp,
        public readonly array $queryParams = [],
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
            self::HEADERS => $this->headers,
            self::ACCEPT_KEY => $this->acceptKey,
            self::COOKIES => $this->cookies,
            self::CLIENT_IP => $this->clientIp,
            self::QUERY_PARAMS => $this->queryParams,
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
            headers: $data[self::HEADERS] ?? [],
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            cookies: $data[self::COOKIES] ?? [],
            clientIp: $data[self::CLIENT_IP] ?? '',
            queryParams: $data[self::QUERY_PARAMS] ?? [],
        );
    }
}
