<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

/** How one live column contributes to a change-log entry. */
enum JournalColumnMode: string
{
    case VALUE = 'value';
    case PERSONAL = 'personal';
    case SECRET = 'secret';
    case NOISE = 'noise';
    case BINARY = 'binary';
    case RECORD_KEY = 'record_key';
}
