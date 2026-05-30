<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * mysqli bind_param type specifiers (i, d, s, b).
 */
enum SqlBindType: string
{
    case INTEGER = 'i';
    case DOUBLE = 'd';
    case STRING = 's';
    case BLOB = 'b';

    public function isNumeric(): bool
    {
        return $this === self::INTEGER || $this === self::DOUBLE;
    }
}
