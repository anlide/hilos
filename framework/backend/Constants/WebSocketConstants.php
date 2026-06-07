<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * WebSocketConstants - WebSocket protocol and application contract constants.
 */
final class WebSocketConstants
{
    /** @var string RFC 6455 Sec-WebSocket-Accept magic string */
    public const string RFC6455_ACCEPT_MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var string Required Sec-WebSocket-Version value (RFC 6455) */
    public const string PROTOCOL_VERSION = '13';

    /**
     * Text-frame keepalive payload from the frontend SDK.
     *
     * Must match framework/frontend WebSocketService default pingMessage.
     */
    public const string KEEPALIVE_TEXT_PING = 'ping';
}
