<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Exception;

use Hilos\Socket\WebSocket\WebSocketException;
use Throwable;

/**
 * Exception thrown when a reserved WebSocket opcode is encountered
 */
class ReservedOpcodeException extends WebSocketException
{
    public function __construct(int $opcode, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Reserved WebSocket opcode encountered: 0x%02X. " .
            "Reserved opcodes (0x3-0x7, 0xB-0xF) must not be used and should result in connection closure.",
            $opcode,
        );
        parent::__construct($message, 0, $previous);
    }
}
