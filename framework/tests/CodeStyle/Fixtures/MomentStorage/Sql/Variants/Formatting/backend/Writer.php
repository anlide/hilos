<?php

declare(strict_types=1);

namespace MomentSqlVariants;

use Hilos\Database\Database as Sql;
use Hilos\Utils\Helpers\TimeHelper as Clock;

class Writer
{
    public function write(): void
    {
        /* Alias names and literal template spelling do not change the SQL write. */
        $when = Clock::getSqlDateTime( /* PHP comment */ );
        Sql::sql('UPDATE /* Moment comment: SET at = NOW() */ event'
            . ' SET at = ? -- ignored NOW() and ?
              WHERE id = ?;', [ $when, /* significant values only */ 7 ]);
    }
}
