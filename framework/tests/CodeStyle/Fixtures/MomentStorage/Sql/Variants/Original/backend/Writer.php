<?php

declare(strict_types=1);

namespace MomentSqlVariants;

use Hilos\Database\Database;
use Hilos\Utils\Helpers\TimeHelper;

class Writer
{
    public function write(): void
    {
        $when = TimeHelper::getSqlDateTime();
        Database::sql('UPDATE event SET at = ? WHERE id = ?', [$when, 7]);
    }
}
