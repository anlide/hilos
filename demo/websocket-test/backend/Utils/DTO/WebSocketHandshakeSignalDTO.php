<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Utils\DTO;

/**
 * WebSocketHandshakeSignalDTO - DTO for WebSocket handshake signal
 *
 * Represents a WebSocket handshake signal sent from WebSocket client to chat agent.
 */
class WebSocketHandshakeSignalDTO extends AbstractChatMessageDTO
{
    // Field name constants
    public const string CLIENT_ID = 'clientId';
    public const string HEADERS = 'headers';
    public const string ACCEPT_KEY = 'acceptKey';
    public const string COOKIES = 'cookies';
    public const string CLIENT_IP = 'clientIp';

    public function __construct(
        public readonly string $clientId,
        public readonly array $headers,
        public readonly string $acceptKey,
        public readonly array $cookies,
        public readonly string $clientIp,
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
            self::HEADERS => $this->headers,
            self::ACCEPT_KEY => $this->acceptKey,
            self::COOKIES => $this->cookies,
            self::CLIENT_IP => $this->clientIp,
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
            headers: $data[self::HEADERS] ?? [],
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            cookies: $data[self::COOKIES] ?? [],
            clientIp: $data[self::CLIENT_IP] ?? '',
        );
    }
}

