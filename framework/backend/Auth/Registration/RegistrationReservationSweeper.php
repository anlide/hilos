<?php

declare(strict_types=1);

namespace Hilos\Auth\Registration;

use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Hilos;

/**
 * RegistrationReservationSweeper - frees the registration holds that ran out (HIL-415).
 *
 * A reservation is a promise with a deadline: an address is held while its code
 * travels, and an abandoned registration must give the address back. Expiry alone
 * would do that if nobody were watching the code screen - but somebody usually is,
 * and a session parked there has to be rolled back to the identifier step rather
 * than left typing a code into a hold that no longer exists. That is why the sweep
 * RETURNS the freed identifiers instead of counting them: they are the address list
 * the converge broadcast is built from.
 *
 * The sweep itself is deliberately dumb (delete what expired, say which) and lives
 * in the framework, while the cron rule that calls it and the broadcast that
 * follows are the project's - the same split as every other framework mechanism a
 * project schedules.
 */
final class RegistrationReservationSweeper
{
    /**
     * Deletes every reservation whose hold ran out and names the freed identifiers.
     *
     * Idempotent and safe to run on any tick: a sweep with nothing to free answers
     * an empty list, and the identifiers it does return are exactly the ones whose
     * row this call removed - so two nodes sweeping cannot both announce the same
     * expiry.
     *
     * @return list<string> Identifiers freed by this sweep (empty when none expired)
     * @throws DatabaseException When a reservation query fails
     * @throws LogicException When the reservations object collection is unavailable
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
