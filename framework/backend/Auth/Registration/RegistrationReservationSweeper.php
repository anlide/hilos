<?php

declare(strict_types=1);

namespace Hilos\Auth\Registration;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Hilos;

/**
 * RegistrationReservationSweeper - frees the registration holds that ran out (HIL-415).
 *
 * A reservation is a promise with a deadline: a browser holds an address while its
 * code travels, and an abandoned registration must end. Expiry alone would do that
 * if nobody were watching the code screen - but somebody usually is, and a session
 * parked there has to be rolled back to the identifier step rather than left typing
 * a code into a hold that no longer exists. That is why the sweep RETURNS the rows
 * it freed instead of counting them: they are what the rollback broadcast is built
 * from.
 *
 * It returns PAIRS and not addresses (HIL-608). A hold belongs to one browser and
 * several browsers may be registering the same address, so an expired hold rolls
 * back the session that owned it and leaves the others waiting for their own codes.
 *
 * The sweep itself is deliberately dumb (delete what expired, say which) and lives
 * in the framework, while the cron rule that calls it and the broadcast that
 * follows are the project's - the same split as every other framework mechanism a
 * project schedules.
 */
final class RegistrationReservationSweeper
{
    /**
     * Deletes every reservation whose hold ran out and names the pairs it freed.
     *
     * Idempotent and safe to run on any tick: a sweep with nothing to free answers
     * an empty list, and the pairs it does return are exactly the ones whose row this
     * call removed - so two nodes sweeping cannot both announce the same expiry.
     *
     * @return list<array{sessionToken: string, identifier: string}> Session/identifier pairs this sweep freed
     * @throws DatabaseException When a reservation query fails
     * @throws LogicException When the reservations object collection is unavailable
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function sweep(): array
    {
        return $this->collection()->deleteExpired();
    }

    /**
     * Resolves the framework-owned reservations object collection.
     *
     * @return ObjectRegistrationReservations Reservation persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     */
    private function collection(): ObjectRegistrationReservations
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::registrationReservations);
        if (!$collection instanceof ObjectRegistrationReservations) {
            throw new LogicException('Registration reservations object collection is not configured');
        }

        return $collection;
    }
}
