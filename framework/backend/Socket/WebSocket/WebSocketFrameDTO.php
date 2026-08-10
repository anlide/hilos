<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket;

use Hilos\BaseDTO;
use Hilos\Socket\Client\WebSocketClient;

/**
 * WebSocketFrameDTO - Data Transfer Object for WebSocket frame data.
 *
 * Represents a parsed WebSocket frame with all its components.
 *
 * Not a {@see BaseDTO}: a frame never travels as an array. It is built from raw
 * bytes in {@see WebSocketClient::parseFrame()} and read field by field in the
 * same class, so the inherited array round-trip had no caller anywhere in the
 * repository — and its `fromArray()` had to invent a value for every field a
 * payload did not carry, which is the one thing a frame parser must not do.
 */
class WebSocketFrameDTO
{
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
}
