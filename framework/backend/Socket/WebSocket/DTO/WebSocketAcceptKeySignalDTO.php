<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

/**
 * Signal DTO that carries a WebSocket accept key.
 */
interface WebSocketAcceptKeySignalDTO
{
    /**
     * Accept key of the connection this signal is bound to.
     *
     * @return ?string Accept key, or null when the signal carries none
     */
    public function getAcceptKey(): ?string;
}
