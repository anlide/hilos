<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * Immutable connection settings for a single MySQL connection index.
 */
final readonly class DatabaseConnectionConfig
{
    /**
     * @param string $host Database host
     * @param string $user Database user
     * @param string $password Database password
     * @param string $database Database name
     * @param int $port Database port
     * @param string $charset Character set with collation
     * @param ?string $socket Unix socket path
     * @param int $reconnectAttempts Reconnect attempt count for sql()
     * @param int $reconnectDelay Delay between reconnects in milliseconds
     */
    public function __construct(
        public string $host,
        public string $user,
        public string $password,
        public string $database,
        public int $port,
        public string $charset,
        public ?string $socket,
        public int $reconnectAttempts,
        public int $reconnectDelay,
    ) {
    }
}
