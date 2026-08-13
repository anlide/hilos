<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;

/**
 * RegistrationReservations entity collection.
 *
 * @extends EntityCollection<EntityRegistrationReservation>
 */
final class RegistrationReservations extends EntityCollection
{
    public const string ENTITY_CLASS = EntityRegistrationReservation::class;
}
