<?php

declare(strict_types=1);

namespace MomentSqlVariants;

use Hilos\Database\Database;

class Writer
{
    public function write(): void
    {
        $when = time();
        Database::sql('UPDATE event SET at = ? WHERE id = ?', [$when, 7]);
    }
}
