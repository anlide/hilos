<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Distinguishes otherwise overlapping receipt and bare-entry row numbers. */
enum ChangeLogFeedItemKind: string
{
    case RECEIPT = 'receipt';
    case ENTRY = 'entry';
}
