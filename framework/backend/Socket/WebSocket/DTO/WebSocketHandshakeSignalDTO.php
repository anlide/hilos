<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketHandshakeSignalDTO - DTO for WebSocket handshake signal.
 *
 * Represents a WebSocket handshake signal sent from WebSocket client.
 */
class WebSocketHandshakeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string HEADERS = 'headers';
    public const string ACCEPT_KEY = 'acceptKey';
    public const string COOKIES = 'cookies';
    public const string CLIENT_IP = 'clientIp';
    public const string QUERY_PARAMS = 'queryParams';
    public const string SESSION_TOKEN = 'sessionToken';

    /**
     * Creates WebSocket handshake signal DTO.
     *
     * @param array<string, string> $headers HTTP headers (lowercase header names)
     * @param string $acceptKey Daemon-minted connection identifier
     * @param array<string, string> $cookies Cookies
     * @param string $clientIp Client IP address
     * @param RequestQueryParams $queryParams Query string params
     * @param string $sessionToken Session token resolved by the daemon (from the cookie, or freshly issued on the handshake)
     */
    public function __construct(
        public readonly array $headers,
        public readonly string $acceptKey,
        public readonly array $cookies,
        public readonly string $clientIp,
        public readonly RequestQueryParams $queryParams = new RequestQueryParams(),
        public readonly string $sessionToken = '',
    ) {
    }

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
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
            self::QUERY_PARAMS => $this->queryParams->toArray(),
            self::SESSION_TOKEN => $this->sessionToken,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When query params are not a string map
     */
    public static function fromArray(array $data): static
    {
        return new static(
            headers: $data[self::HEADERS] ?? [],
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            cookies: $data[self::COOKIES] ?? [],
            clientIp: $data[self::CLIENT_IP] ?? '',
            queryParams: RequestQueryParams::fromStringMap(
                is_array($data[self::QUERY_PARAMS] ?? null) ? $data[self::QUERY_PARAMS] : [],
            ),
            sessionToken: (string)($data[self::SESSION_TOKEN] ?? ''),
        );
    }
}
