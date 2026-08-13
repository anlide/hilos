<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\View\Item\RegistrationReservation;

/**
 * RegistrationReservations Db collection.
 *
 * Read-facing representation of the framework-owned
 * hilos_registration_reservation table. The reserve/confirm/sweep orchestration
 * runs in {@see RegistrationReservationService} against the object-layer
 * primitives ({@see ObjectRegistrationReservations}); no read API is exposed here
 * and nothing is published to the frontend - a browser learns that an address is
 * held only by submitting it, never by reading the holds.
 *
 * @extends DbCollection<RegistrationReservation, ObjectRegistrationReservations>
 */
final class RegistrationReservations extends DbCollection
{
    public const string DB_ITEM_CLASS = RegistrationReservation::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectRegistrationReservations::class;
}
