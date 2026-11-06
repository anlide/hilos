<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** Absence is established only after the entire dump has passed admission. */
enum MigrationHistoryState
{
    case ABSENT;
    case EMPTY;
    case RECORDED;
}
