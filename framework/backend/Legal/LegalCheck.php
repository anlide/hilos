<?php

declare(strict_types=1);

namespace Hilos\Legal;

/** Checks available in the legal administration projection. */
enum LegalCheck: string
{
    case UNDECLARED_REVISION = 'undeclared_revision';
    case ZERO_WINDOW = 'zero_window';
    case NEWER_STANDARD_SET = 'newer_standard_set';
    case DEVIATIONS = 'deviations';
}
