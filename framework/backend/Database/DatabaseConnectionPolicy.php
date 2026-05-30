<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * Reconnect, retry, and query timeout policy for MySQL access layer.
 */
final class DatabaseConnectionPolicy
{
    public const int MS_PER_SECOND = 1000;

    public const int ATTEMPTS_WITHOUT_RECONNECT = 1;

    public const int RECONNECT_DELAY_MIN_MS = 1000;

    public const int RECONNECT_TIMEOUT_MAX_MS = 5000;

    public const int DEFAULT_RECONNECT_ATTEMPTS = 3;

    public const int DEFAULT_RECONNECT_DELAY_MS = 100;

    public const int CONNECT_RETRY_MAX_ATTEMPTS = 30;

    public const int CONNECT_RETRY_DELAY_SECONDS = 2;

    public const int DEFAULT_SQL_RUN_TIMEOUT_SECONDS = 30;
}
