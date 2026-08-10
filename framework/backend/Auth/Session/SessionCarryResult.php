<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

/**
 * SessionCarryResult - what a carry-over pass did, in two numbers (HIL-479).
 *
 * `carried` counts the sessions written into the restored database, `dropped` the ones whose
 * owner will be anonymous at the next handshake - expired, unrecognizable, or lost to a
 * database error. The two do not have to add up to the snapshot: a token that already had a
 * row came back with the archive, so its owner stays logged in without this mechanism doing
 * anything, and counting that as either would misreport the one thing the numbers are read
 * for - how many people the restore logged out.
 */
final class SessionCarryResult
{
    /**
     * @param int $carried Sessions written into the restored database
     * @param int $dropped Sessions that will not survive the restore
     */
    public function __construct(
        public readonly int $carried,
        public readonly int $dropped,
    ) {
    }
}
