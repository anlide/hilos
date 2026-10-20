<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Tests;

use Hilos\Database\Database as Db;

trait InnerHelper
{
    protected function clearRows(): void
    {
        Db::sqlRun('DELETE FROM fixture');
        Db::transactionCommit();
    }
}

trait OuterHelper
{
    use InnerHelper;
}

final class LocalHelper
{
    public static function unsafe(): void
    {
        Db::sqlRun('ALTER TABLE fixture ADD name TEXT');
    }

    public function recurse(): void
    {
        $this->recurse();
        Db::sqlRun('ROLLBACK');
    }
}

final class UnusedHelper
{
    public static function unsafe(): void
    {
        Db::sqlRun('DROP TABLE unrelated');
    }
}
