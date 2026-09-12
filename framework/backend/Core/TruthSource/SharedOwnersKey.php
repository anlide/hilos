<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Hilos;

/**
 * Config keys for one row of {@see Hilos::SHARED_DB_OWNERS} and {@see Hilos::SHARED_RT_OWNERS}.
 *
 * A row is a receipt, not a permission: it says that two owners of one collection are known to
 * the project and names the leaf that will part them. The topology validator refuses a start over
 * a pair no row covers, and refuses one over a row whose pair no longer collides - so a receipt
 * cannot be written ahead of the collision, and cannot outlive it either.
 *
 * ```php
 * public const array SHARED_DB_OWNERS = [
 *     ChatDbContext::users => [
 *         SharedOwnersKey::OWNERS => [ChatAgent::class, UsersLibraryAgent::class],
 *         SharedOwnersKey::DEBT => 'HIL-630',
 *     ],
 * ];
 * ```
 */
final class SharedOwnersKey
{
    /**
     * Owner classes sharing the collection: at least two, each named once, each registered in
     * {@see Hilos::AGENTS}.
     */
    public const string OWNERS = 'owners';

    /**
     * Who will part these owners: the key of the leaf that does it, or the reason in words when
     * the place is meant to stay. Checked by machine rather than left to a comment beside the
     * row, because a list of receipts without a single addressee is what this one turns into
     * within half a year.
     */
    public const string DEBT = 'debt';
}
