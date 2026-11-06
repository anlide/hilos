<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** OTHER has no table effects; DATA carries direct row access; UNKNOWN provides no effect assurance. */
enum MigrationOperationKind
{
    case CREATE_TABLE;
    case ALTER_TABLE;
    case DROP_TABLE;
    case RENAME_TABLE;
    case DATA;
    case DROP_TRIGGER;
    case OTHER;
    case UNKNOWN;
}
