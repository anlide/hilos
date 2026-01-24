<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Constants;

/**
 * HTTP Headers constants
 * Project-specific HTTP header names
 */
final class HttpHeaders
{
    /**
     * Session token header name
     * Used for WebSocket authentication
     */
    public const string SESSION_TOKEN = 'X-Session-Token';
}
