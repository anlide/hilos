<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/** Stable assignment keys within a merge operation. */
enum AccountMergeDuty: string
{
    case PrepareSurvivor = 'prepare_survivor';
    case PrepareLoser = 'prepare_loser';
    case Give = 'give';
    case ReleaseLoser = 'release_loser';
    case ReleaseSurvivor = 'release_survivor';
}
