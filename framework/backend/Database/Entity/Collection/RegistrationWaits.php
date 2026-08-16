<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\RegistrationWait as EntityRegistrationWait;

/**
 * RegistrationWaits entity collection.
 *
 * @extends EntityCollection<EntityRegistrationWait>
 */
final class RegistrationWaits extends EntityCollection
{
    public const string ENTITY_CLASS = EntityRegistrationWait::class;
}
