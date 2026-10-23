<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\DatabaseException;

/** A logical database name or its connection configuration cannot be resolved. */
class NamedDatabaseException extends DatabaseException
{
}
