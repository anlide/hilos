<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\WebSocket;

use Hilos\Exception\Socket\WebSocketException;
use Throwable;

/**
 * Exception thrown when an unknown or invalid WebSocket opcode is encountered
 */
class UnknownOpcodeException extends WebSocketException
{
    public function __construct(int $opcode, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Unknown or invalid WebSocket opcode: 0x%02X. " .
            "Valid opcodes are: 0x0 (continuation), 0x1 (text), 0x2 (binary), " .
            "0x8 (close), 0x9 (ping), 0xA (pong).",
            $opcode,
        );
        parent::__construct($message, 0, $previous);
    }
}
