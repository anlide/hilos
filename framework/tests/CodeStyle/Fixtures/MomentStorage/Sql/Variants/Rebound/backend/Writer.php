<?php

declare(strict_types=1);

namespace MomentSqlVariants;

use Hilos\Database\Database;
use function date;

class Writer
{
    public function write(): void
    {
        $when = date('Y-m-d H:i:s');
        Database::sql('UPDATE event SET id = ? WHERE at = ?', [$when, 7]);
    }
}
