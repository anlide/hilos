<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\PasskeyCredential as EntityPasskeyCredential;

/**
 * PasskeyCredentials entity collection.
 *
 * @extends EntityCollection<EntityPasskeyCredential>
 */
final class PasskeyCredentials extends EntityCollection
{
    public const string ENTITY_CLASS = EntityPasskeyCredential::class;
}
