<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * MySQL client (CR_*) error codes used for reconnect and retry policy.
 */
enum MysqlClientErrorCode: int
{
    case CONNECTION_ERROR = 2002;
    case CONN_HOST_ERROR = 2003;
    case SERVER_GONE = 2006;
    case SERVER_LOST = 2013;

    /**
     * @return list<int>
     */
    public static function connectionLostValues(): array
    {
        return [
            self::SERVER_GONE->value,
            self::SERVER_LOST->value,
        ];
    }

    /**
     * @return list<int>
     */
    public static function temporaryConnectFailureValues(): array
    {
        return [
            self::CONNECTION_ERROR->value,
            self::CONN_HOST_ERROR->value,
        ];
    }

    public static function isConnectionLost(int $errno): bool
    {
        return in_array($errno, self::connectionLostValues(), true);
    }

    public static function isTemporaryConnectFailure(int $errno): bool
    {
        return in_array($errno, self::temporaryConnectFailureValues(), true);
    }
}
