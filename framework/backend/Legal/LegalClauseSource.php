<?php

declare(strict_types=1);

namespace Hilos\Legal;

/** Vocabulary of LegalClauseSource (HIL-498). */
enum LegalClauseSource: string
{
    case STANDARD = 'standard';
    case DEVIATION = 'deviation';
}
