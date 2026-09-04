<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

/**
 * SessionCarryResult - what a carry-over pass did, in three numbers (HIL-479, HIL-726).
 *
 * `carried` counts the sessions written into the restored database, `dropped` the ones whose
 * owner will be anonymous at the next handshake - expired, unrecognizable, or lost to a
 * database error, and `kept` the ones that needed no carrying: a token that already had a row
 * came back with the archive, so its owner stays logged in without this mechanism doing
 * anything. That case is still neither a carry nor a loss, which is why it gets a number of
 * its own instead of being folded into one of the other two - the one thing these numbers are
 * read for is how many people the restore logged out. Named, it also makes the three add up to
 * the snapshot; two numbers did not, and the gap was where the outcome hid (HIL-664).
 */
final class SessionCarryResult
{
    /**
     * @param int $carried Sessions written into the restored database
     * @param int $dropped Sessions that will not survive the restore
     * @param int $kept Sessions that came back inside the archive and needed no carrying
     */
    public function __construct(
        public readonly int $carried,
        public readonly int $dropped,
        public readonly int $kept,
    ) {
    }
}
