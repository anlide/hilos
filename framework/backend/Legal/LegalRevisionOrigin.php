<?php

declare(strict_types=1);

namespace Hilos\Legal;

/** Vocabulary of LegalRevisionOrigin (HIL-498). */
enum LegalRevisionOrigin: string
{
    case FIRST = 'first';
    case PROJECT = 'project';
    case STANDARD = 'standard';
}
