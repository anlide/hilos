<?php

declare(strict_types=1);

namespace Hilos\Legal;

/** Vocabulary of LegalChangeKind (HIL-498). */
enum LegalChangeKind: string
{
    case CHANGED = 'changed';
    case ADDED = 'added';
    case REMOVED = 'removed';
}
