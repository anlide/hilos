<?php

declare(strict_types=1);

namespace MomentSqlVariants;

use Hilos\Database\Database as Sql;
use function date;

class Writer
{
    public function write(): void
    {
        /* Alias names and literal template spelling do not change the SQL write. */
        $when = date( /* PHP comment */ 'Y-m-d H:i:s' );
        Sql::sql('UPDATE /* Moment comment: SET at = NOW() */ event'
            . ' SET at = ? -- ignored NOW() and ?
              WHERE id = ?;', [ $when, /* significant values only */ 7 ]);
    }
}
