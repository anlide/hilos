<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/** Stable merge stages shared by the coordinator and its participants. */
enum AccountMergeStage: string
{
    case Preparing = 'preparing';
    case Go = 'go';
    case Cancelling = 'cancelling';
}
