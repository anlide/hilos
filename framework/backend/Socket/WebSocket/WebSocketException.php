<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket;

use Hilos\Core\Exception\MalformedInput;
use Hilos\HilosException;

/**
 * Base exception for WebSocket-related errors.
 *
 * Carries the marker for the whole family: every one of these classes means a frame,
 * a buffer or a handshake that arrived and could not be read as WebSocket.
 */
class WebSocketException extends HilosException implements MalformedInput
{
}
