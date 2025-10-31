<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket;

use Exception;
use Throwable;

/**
 * Base exception for WebSocket-related errors
 */
class WebSocketException extends Exception implements Throwable
{
}

