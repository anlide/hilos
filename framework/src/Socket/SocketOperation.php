<?php

declare(strict_types=1);

namespace Hilos\Socket;

/**
 * SocketOperation - Socket operation type enumeration
 *
 * Represents possible socket operations for error handling.
 */
enum SocketOperation: string
{
    case READ = 'read';
    case WRITE = 'write';
    case CLOSE = 'close';
    case GETPEERNAME = 'getpeername';
}

