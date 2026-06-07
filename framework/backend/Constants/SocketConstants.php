<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * SocketConstants - Socket-related constants.
 *
 * Defines limits and constraints for socket operations.
 */
final class SocketConstants
{
    /** @var int Maximum read buffer size in bytes (1GB) */
    public const int MAX_READ_BUFFER_SIZE = 1073741824;

    /** @var int Maximum JSON nesting depth */
    public const int MAX_JSON_DEPTH = 100;

    /** @var int Default socket_listen backlog (typical Linux SOMAXCONN) */
    public const int DEFAULT_LISTEN_BACKLOG = 128;
}
