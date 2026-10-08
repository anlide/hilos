<?php

declare(strict_types=1);

namespace Hilos\Users;

/**
 * The one standing of an account that is shown, out of the facts that compose it (HIL-945).
 *
 * Ordered by how much each takes away: a merge took the account whole and left a tombstone, a
 * block takes the account, a freeze takes the product and leaves the exits, a scheduled deletion
 * takes nothing yet. When several facts hold at once the first of them in that order is the one
 * shown; the others stay named on the verdict itself ({@see AccountStanding}).
 */
enum AccountStandingKind: string
{
    /** Nothing holds: the account is used as usual. */
    case NONE = 'none';

    /**
     * The account was folded into another one by a merge (HIL-1292).
     *
     * Not a punishment: the merge closed its sign-in with the block flag, and that flag stays
     * named on the verdict, but what the account is now is the tombstone - so this is shown
     * ahead of the block.
     */
    case MERGED = 'merged';

    /** An administrator blocked the account. */
    case BLOCKED = 'blocked';

    /** A substantial revision the person has not accepted is past its deadline, and the installation freezes then. */
    case FROZEN = 'frozen';

    /** The person asked for their account to be deleted, and the grace period is running. */
    case DELETION_SCHEDULED = 'deletion_scheduled';
}
