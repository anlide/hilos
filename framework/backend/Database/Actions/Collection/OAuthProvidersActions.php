<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\Actions\Item\OAuthProviderActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\OAuthProviders as ObjectOAuthProviders;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\OAuthProvider as ObjectOAuthProvider;
use Hilos\Database\View\Collection\OAuthProviders as DbCollectionOAuthProviders;
use Hilos\Database\View\Item\OAuthProvider;
use Hilos\HilosException;

/**
 * OAuthProvidersActions - write operations for the OAuth providers collection (HIL-286).
 *
 * The collection-level write is the one that brings a provider's row into being; every
 * field of an existing row is written through the row's own actions
 * ({@see OAuthProviderActions}). A row starts empty: every
 * column NULL, the provider on its env values and recipe until the administrator
 * enters something.
 *
 * @extends DbActions<OAuthProvider, ObjectOAuthProviders>
 * @property-read DbCollectionOAuthProviders $collection
 * @property-read ObjectOAuthProviders $objectCollection
 */
final class OAuthProvidersActions extends DbActions
{
    /**
     * Adds the empty row of one provider.
     *
     * @param string $providerKey Provider key the project declares, e.g. 'oauth:github'
     * @return OAuthProvider Created provider Db item
     * @throws EmptyValueException When the provider key is empty
     * @throws DuplicateValueException When the provider already has a row
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading or row persistence fails
     * @throws DuplicateIdException When the created row id already exists in the collection
     * @throws ObjectGetIdStringNotImplementedException When the created row has no persisted id
     * @throws TableNameUndeterminedException When duplicate-id reporting cannot resolve the table name
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws CreateNotAllowedException When the truth source rejects the row creation
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    public function add(string $providerKey): OAuthProvider
    {
        $this->ensureCanCreate();

        if ($providerKey === '') {
            throw new EmptyValueException('OAuth provider key cannot be empty');
        }
        if (isset($this->collection[$providerKey])) {
            throw new DuplicateValueException("OAuth provider '{$providerKey}' already has a row");
        }

        $provider = ObjectOAuthProvider::create();
        $provider->providerKey = $providerKey;
        $provider->sync();

        $this->addObjectToCollection($provider);

        return $this->createDbItemFromObject($provider);
    }
}
