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

    /**
     * Connect failures a reconnect inside a statement waits out. Anything else is final.
     *
     * @return list<int> Retried MySQL client error codes
     */
    public static function retriedOnReconnectValues(): array
    {
        return [...self::connectionLostValues(), ...self::temporaryConnectFailureValues()];
    }

    public static function isConnectionLost(int $errno): bool
    {
        return in_array($errno, self::connectionLostValues(), true);
    }

    public static function isTemporaryConnectFailure(int $errno): bool
    {
        return in_array($errno, self::temporaryConnectFailureValues(), true);
    }

    /**
     * @param int $errno MySQL client error code
     * @return bool Whether reconnect waits out this failure
     */
    public static function isRetriedOnReconnect(int $errno): bool
    {
        return in_array($errno, self::retriedOnReconnectValues(), true);
    }
}
