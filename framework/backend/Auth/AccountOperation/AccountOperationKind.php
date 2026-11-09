<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/** The business protocol carried by an account operation. */
enum AccountOperationKind: string
{
    case Erasure = 'erasure';
    case Merge = 'merge';
}
