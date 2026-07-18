<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\Identity as EntityIdentity;

/**
 * Identities entity collection.
 *
 * @extends EntityCollection<EntityIdentity>
 */
final class Identities extends EntityCollection
{
    public const string ENTITY_CLASS = EntityIdentity::class;
}
