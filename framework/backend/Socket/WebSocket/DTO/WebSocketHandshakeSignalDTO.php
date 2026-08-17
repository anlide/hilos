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
    public const string INHERITED_ACK = 'inheritedAck';

    /**
     * Creates WebSocket handshake signal DTO.
     *
     * @param array<string, string> $headers HTTP headers (lowercase header names)
     * @param string $acceptKey Daemon-minted connection identifier
     * @param array<string, string> $cookies Cookies
     * @param ?string $clientIp Client IP address, or null when the transport exposes none
     * @param RequestQueryParams $queryParams Query string params
     * @param string $sessionToken Session token resolved by the daemon (from the cookie, or freshly issued on the handshake)
     * @param ?string $inheritedAck Success ack the traded rotation carried over to this socket, or null for the ordinary handshake
     */
    public function __construct(
        public readonly array $headers,
        public readonly string $acceptKey,
        public readonly array $cookies,
        public readonly ?string $clientIp,
        public readonly RequestQueryParams $queryParams = new RequestQueryParams(),
        public readonly string $sessionToken = '',
        public readonly ?string $inheritedAck = null,
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
            self::INHERITED_ACK => $this->inheritedAck,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * Every field toArray() writes unconditionally is required here, headers and
     * cookies included: a request carrying neither is written as two empty maps,
     * so an absent key is a truncated payload rather than a bare request. Two
     * fields are genuinely absent rather than truncated: the client IP the
     * transport may not expose, and the ack only a handshake that traded a
     * rotation ticket carries.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a required field is missing, or query params are not a string map
     */
    public static function fromArray(array $data): static
    {
        return new static(
            headers: self::requireArray($data, self::HEADERS),
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            cookies: self::requireArray($data, self::COOKIES),
            clientIp: self::optionalString($data, self::CLIENT_IP),
            queryParams: RequestQueryParams::fromStringMap(self::requireArray($data, self::QUERY_PARAMS)),
            sessionToken: self::requireString($data, self::SESSION_TOKEN),
            inheritedAck: self::optionalString($data, self::INHERITED_ACK),
        );
    }
}
