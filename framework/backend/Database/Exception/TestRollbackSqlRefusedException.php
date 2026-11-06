<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

/** SQL refused by an active test rollback scope. */
final class TestRollbackSqlRefusedException extends DatabaseRuntimeException
{
}
