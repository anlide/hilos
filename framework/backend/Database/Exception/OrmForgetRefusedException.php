<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\DatabaseException;

/**
 * The test-only ORM reset cannot run in this environment or with an open transaction.
 */
final class OrmForgetRefusedException extends DatabaseException
{
    /** @return self Refusal for an unknown or production-like environment */
    public static function forEnvironment(): self
    {
        return new self('ORM test reset requires a known non-production environment');
    }

    /** @return self Refusal while any connection still holds a transaction level */
    public static function forOpenTransaction(): self
    {
        return new self('ORM test reset is refused while a transaction is open on any connection');
    }
}
