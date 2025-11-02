<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Utils\DTO;

/**
 * WebSocketFrameSignalDTO - DTO for WebSocket frame signal
 *
 * Represents a WebSocket frame signal sent from WebSocket client to chat agent.
 */
class WebSocketFrameSignalDTO extends AbstractChatMessageDTO
{
    // Field name constants
    public const string CLIENT_ID = 'clientId';
    public const string PAYLOAD = 'payload';
    public const string IS_BINARY = 'isBinary';

    public function __construct(
        public readonly string $clientId,
        public readonly string $payload,
        public readonly bool $isBinary = false,
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
            self::IS_BINARY => $this->isBinary,
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
            isBinary: $data[self::IS_BINARY] ?? false,
        );
    }
}

