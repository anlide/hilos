<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\UserVerification as EntityUserVerification;

/**
 * UserVerifications entity collection.
 *
 * @extends EntityCollection<EntityUserVerification>
 */
final class UserVerifications extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUserVerification::class;
}
