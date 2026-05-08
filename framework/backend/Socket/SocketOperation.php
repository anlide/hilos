<?php

declare(strict_types=1);

namespace Hilos\Socket;

/**
 * SocketOperation - Socket operation type enumeration.
 *
 * Represents possible socket operations for error handling.
 */
enum SocketOperation: string
{
    case READ = 'read';
    case WRITE = 'write';
    case CLOSE = 'close';
    case GETPEERNAME = 'getpeername';
    case CREATE = 'create';
    case SELECT = 'select';
    case SET_OPTION = 'set_option';
    case SET_NONBLOCK = 'set_nonblock';
    case CONNECT = 'connect';
    case BIND = 'bind';
    case LISTEN = 'listen';
    case ACCEPT = 'accept';
}
