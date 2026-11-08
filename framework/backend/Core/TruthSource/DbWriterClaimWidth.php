<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

/** Class-level comparison widths; these do not add a runtime grant width. */
enum DbWriterClaimWidth: string
{
    case Whole = 'whole';
    case Rows = 'rows';
    case Set = 'set';
}
