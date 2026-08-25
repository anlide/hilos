<?php

declare(strict_types=1);

namespace Hilos\Database\Identity;

use Hilos\Database\Object\Collection\Identities;

/**
 * PasswordFate - whose password an account keeps when two accounts become one (HIL-692).
 *
 * An account holds at most one password, so a merge of two that each have one is the
 * single place where something has to give, and no rule can pick which: both secrets
 * are equally the person's. The operator names it, and the value travels from the
 * command into {@see Identities::rePointToUser()} without being re-derived on the way;
 * hence the backed values.
 *
 * The name says whose password STAYS, not whose is erased, and it says it the same way
 * whether or not that account has one - naming the survivor's when the survivor has no
 * password is a legitimate outcome ("this person ends up without one"), not bad input.
 */
enum PasswordFate: string
{
    /** The survivor's password stays; a password of the loser is demoted. */
    case SURVIVOR = 'survivor';

    /** The loser's password stays and moves across; a password of the survivor is demoted. */
    case LOSER = 'loser';

    /** Neither password stays; both are demoted and the person sets one anew in the profile. */
    case NONE = 'none';
}
