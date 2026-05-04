<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

/**
 * Signal DTO that carries a WebSocket accept key.
 */
interface WebSocketAcceptKeySignalDTO
{
    public function getAcceptKey(): string;
}
