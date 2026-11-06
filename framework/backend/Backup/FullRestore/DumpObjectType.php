<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** Object kinds emitted by the supported mysqldump grammar. */
enum DumpObjectType
{
    case TABLE;
    case VIEW;
}
