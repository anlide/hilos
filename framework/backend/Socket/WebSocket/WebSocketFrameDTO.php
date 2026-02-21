<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket;

use Hilos\BaseDTO;

/**
 * WebSocketFrameDTO - Data Transfer Object for WebSocket frame data
 *
 * Represents a parsed WebSocket frame with all its components.
 */
class WebSocketFrameDTO extends BaseDTO
{
    // Field name constants
    public const string FIN = 'fin';
    public const string OPCODE = 'opcode';
    public const string MASKED = 'masked';
    public const string PAYLOAD = 'payload';

    public function __construct(
        public readonly int $fin,
        public readonly int $opcode,
        public readonly int $masked,
        public readonly string $payload,
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
            self::FIN => $this->fin,
            self::OPCODE => $this->opcode,
            self::MASKED => $this->masked,
            self::PAYLOAD => $this->payload,
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
            fin: $data[self::FIN] ?? 0,
            opcode: $data[self::OPCODE] ?? 0,
            masked: $data[self::MASKED] ?? 0,
            payload: $data[self::PAYLOAD] ?? '',
        );
    }
}
