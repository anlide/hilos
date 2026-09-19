<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\OAuthProvider as EntityOAuthProvider;

/**
 * OAuth providers entity collection.
 *
 * @extends EntityCollection<EntityOAuthProvider>
 */
final class OAuthProviders extends EntityCollection
{
    public const string ENTITY_CLASS = EntityOAuthProvider::class;
}
