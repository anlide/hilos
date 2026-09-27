<?php

declare(strict_types=1);

namespace Hilos\Legal;

/** Vocabulary of LegalStanding (HIL-498). */
enum LegalStanding: string
{
    case NONE = 'none';
    case COVERED = 'covered';
    case WINDOW = 'window';
    case LAPSED = 'lapsed';
}
