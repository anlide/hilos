<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\OAuthProviders as EntityOAuthProviders;
use Hilos\Database\Object\Item\OAuthProvider as ObjectOAuthProvider;
use Hilos\Database\Object\Objects;

/**
 * OAuth providers object collection (HIL-286).
 *
 * Read whole on the first lookup by provider key, and answered from memory after that
 * (HIL-1080): the table holds one row per provider the project declares, so it is tiny, and
 * whether a provider is ready to sign anybody in is asked on every handshake. Once read, the
 * collection declares itself whole, so a row created by another process or node arrives by
 * synchronization, and a reset reads it whole again. A process that never looks a provider
 * up never reads the table, so a project without it stays inert.
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
     * The first lookup in the process reads the whole table; every lookup after it, a missing
     * provider included, is answered from memory.
     *
     * @param string $providerKey Provider key, e.g. 'oauth:github'
     * @return ?ObjectOAuthProvider Provider row object, or null when the provider has no row
     * @throws DatabaseException If the database query fails
     * @throws LogicException When the entity collection class is not configured
     */
    public function findByProviderKey(string $providerKey): ?ObjectOAuthProvider
    {
        if ($providerKey === '') {
            return null;
        }

        $this->preloadAll();
        foreach ($this->objects as $provider) {
            if ($provider->providerKey === $providerKey) {
                return $provider;
            }
        }

        return null;
    }
}
