<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Backend;

use Hilos\Database\Database;

final class Product
{
    public static function transact(): void
    {
        Database::transactionStart();
        Database::sqlRun('UPDATE fixture SET id = 2');
        Database::transactionCommit();
    }
}
