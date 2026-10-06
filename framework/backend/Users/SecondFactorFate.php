<?php

declare(strict_types=1);

namespace Hilos\Users;

/** The administrator's choice when the folded account has a confirmed authenticator. */
enum SecondFactorFate: string
{
    case SURVIVOR = 'survivor';
    case BOTH = 'both';
}
