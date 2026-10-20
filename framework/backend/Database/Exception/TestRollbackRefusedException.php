<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\DatabaseException;

/** The test harness cannot open or safely finish its rollback-only scope. */
final class TestRollbackRefusedException extends DatabaseException
{
}
