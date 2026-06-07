<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket;

use Hilos\BaseDTO;

/**
 * WebSocketFrameDTO - Data Transfer Object for WebSocket frame data.
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

    /**
     * Creates WebSocket frame DTO.
     *
     * @param int $fin FIN bit (1 for final frame)
     * @param int $opcode Opcode (e.g. text, binary, close)
     * @param int $masked Mask bit (1 if masked)
     * @param string $payload Frame payload
     */
    public function __construct(
        public readonly int $fin,
        public readonly int $opcode,
        public readonly int $masked,
        public readonly string $payload,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, int|string> DTO data with fin, opcode, masked, payload keys
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
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data with fin, opcode, masked, payload keys
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            fin: $data[self::FIN] ?? 0,
            opcode: $data[self::OPCODE] ?? 0,
            masked: $data[self::MASKED] ?? 0,
            payload: $data[self::PAYLOAD] ?? '',
        );
    }
}
