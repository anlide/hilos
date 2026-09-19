<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\OAuthProvidersActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\OAuthProviders as ObjectOAuthProviders;
use Hilos\Database\View\Item\OAuthProvider;

/**
 * OAuth providers Db collection (HIL-286).
 *
 * Provider rows support key-based array access: `$oauthProviders[$providerKey]`, the
 * provider key the project declares ('oauth:github'). Integer offsets are still treated
 * as DB primary keys. A provider without a row reads as null - it is simply not
 * configured in the admin.
 *
 * @extends DbCollection<OAuthProvider, ObjectOAuthProviders>
 * @property-read OAuthProvidersActions $actions
 */
final class OAuthProviders extends DbCollection
{
    public const string DB_ITEM_CLASS = OAuthProvider::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectOAuthProviders::class;

    /**
     * Checks whether a provider row exists by provider key or primary id.
     *
     * @param mixed $offset Provider key string or primary id integer
     * @return bool True when the provider row exists
     * @throws DatabaseException On database error while resolving the row
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    /**
     * Returns a provider row by provider key or primary id.
     *
     * @param mixed $offset Provider key string or primary id integer
     * @return ?OAuthProvider Provider Db item, or null when the provider has no row
     * @throws DatabaseException On database error while resolving the row
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function offsetGet(mixed $offset): ?OAuthProvider
    {
        if (is_string($offset)) {
            return $this->findByProviderKey($offset);
        }
        if (!is_int($offset)) {
            return null;
        }

        /** @var ?OAuthProvider $provider */
        $provider = parent::offsetGet($offset);
        return $provider;
    }

    /**
     * Finds a provider row by provider key for the collection offset implementation.
     *
     * @param string $providerKey Provider key, e.g. 'oauth:github'
     * @return ?OAuthProvider Provider Db item, or null when the provider has no row
     * @throws DatabaseException On database error while loading the row
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    private function findByProviderKey(string $providerKey): ?OAuthProvider
    {
        $objectProvider = $this->objectCollection->findByProviderKey($providerKey);

        if ($objectProvider?->id === null) {
            return null;
        }

        /** @var ?OAuthProvider $provider */
        $provider = $this->getItemForKey($objectProvider->id);
        return $provider;
    }
}
