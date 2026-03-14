<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketHandshakeSignalDTO - DTO for WebSocket handshake signal.
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

    /**
     * Creates WebSocket handshake signal DTO.
     *
     * @param array<string, string> $headers HTTP headers
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $cookies Cookies
     * @param string $clientIp Client IP address
     * @param array<string, string> $queryParams Query string params
     */
    public function __construct(
        public readonly array $headers,
        public readonly string $acceptKey,
        public readonly array $cookies,
        public readonly string $clientIp,
        public readonly array $queryParams = [],
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
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
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
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
