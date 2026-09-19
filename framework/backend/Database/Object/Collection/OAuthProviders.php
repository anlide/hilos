<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\OAuthProviders as EntityOAuthProviders;
use Hilos\Database\Entity\Item\OAuthProvider as EntityOAuthProvider;
use Hilos\Database\Object\Item\OAuthProvider as ObjectOAuthProvider;
use Hilos\Database\Object\Objects;

/**
 * OAuth providers object collection (HIL-286).
 *
 * Loaded by key, never as a full set: the set of providers is the project's code, so
 * a reader asks for the rows of the providers it already knows.
 *
 * @extends Objects<ObjectOAuthProvider>
 * @method ObjectOAuthProvider|null current()
 * @method ObjectOAuthProvider|null first()
 * @method ObjectOAuthProvider|null last()
 * @method ObjectOAuthProvider|null get(int|string $key)
 * @method ObjectOAuthProvider|null offsetGet(mixed $offset)
 */
final class OAuthProviders extends Objects
{
    public const string OBJECT_CLASS = ObjectOAuthProvider::class;
    public const string ENTITY_COLLECTION_CLASS = EntityOAuthProviders::class;
    public const string COLLECTION_KEY = HilosDbContext::oauthProviders;

    /**
     * Finds the row of one provider by its provider key.
     *
     * @param string $providerKey Provider key, e.g. 'oauth:github'
     * @return ?ObjectOAuthProvider Provider row object, or null when the provider has no row
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findByProviderKey(string $providerKey): ?ObjectOAuthProvider
    {
        if ($providerKey === '') {
            return null;
        }

        $entityProvider = EntityOAuthProvider::get([EntityOAuthProvider::provider_key => $providerKey])->first();

        if ($entityProvider === null) {
            return null;
        }

        if (!isset($this->objects[$entityProvider->id])) {
            $this->hydrate($entityProvider->id, ObjectOAuthProvider::fromEntity($entityProvider));
        }

        return $this->objects[$entityProvider->id];
    }
}
