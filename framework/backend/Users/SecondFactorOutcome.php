<?php

declare(strict_types=1);

namespace Hilos\Users;

/** Whose confirmed authenticators and backup codes remain after an account merge. */
enum SecondFactorOutcome: string
{
    case NONE = 'none';
    case SURVIVOR = 'survivor';
    case LOSER = 'loser';
    case BOTH = 'both';

    /** @return string The protection now required by the merged account */
    public function describe(): string
    {
        return match ($this) {
            self::NONE => 'No confirmed authenticator remains.',
            self::SURVIVOR => 'Only the survivor\'s authenticator apps and backup codes remain valid.',
            self::LOSER => 'The loser\'s authenticator apps and backup codes now protect the survivor.',
            self::BOTH => 'Authenticator apps and backup codes from either account now open the survivor.',
        };
    }
}
