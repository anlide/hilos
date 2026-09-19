<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\OAuthProvider as ObjectOAuthProvider;
use Hilos\Database\View\Item\OAuthProvider;

/**
 * OAuthProviderActions - write operations for one OAuth provider row (HIL-286).
 *
 * One write per field. A null value takes the field out of the admin layer, and the
 * provider falls back to its env value and then to its recipe for it.
 *
 * @extends DbActions<OAuthProvider, ObjectOAuthProvider>
 * @property-read ObjectOAuthProvider $object
 */
final class OAuthProviderActions extends DbActions
{
    /**
     * Updates the client id the administrator entered, or clears it with null.
     *
     * @param ?string $clientId New client id, or null to fall back to env
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws DatabaseException When collection loading or row persistence fails
     * @throws ObjectGetIdStringNotImplementedException When the primary key is null during the per-item write check
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the row write
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     */
    public function updateClientId(?string $clientId): void
    {
        $this->ensureCanWrite();
        $this->requirePersisted();

        $this->object->clientId = $clientId;
        $this->object->sync();
    }

    /**
     * Updates the requested scope the administrator entered, or clears it with null.
     *
     * @param ?string $scope New space-separated scope, or null to fall back to env and the recipe
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws DatabaseException When collection loading or row persistence fails
     * @throws ObjectGetIdStringNotImplementedException When the primary key is null during the per-item write check
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the row write
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     */
    public function updateScope(?string $scope): void
    {
        $this->ensureCanWrite();
        $this->requirePersisted();

        $this->object->scope = $scope;
        $this->object->sync();
    }

    /**
     * Replaces the client secret, or erases it with null.
     *
     * The value goes straight to the column the ORM does not map; nothing of it is kept
     * on the object ({@see ObjectOAuthProvider::writeClientSecret()}).
     *
     * @param ?string $secret New secret, or null to fall back to env
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws DatabaseException When collection loading or the secret update fails
     * @throws ObjectGetIdStringNotImplementedException When the primary key is null during the per-item write check
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the row write
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function writeClientSecret(?string $secret): void
    {
        $this->ensureCanWrite();
        $this->requirePersisted();

        $this->object->writeClientSecret($secret);
    }

    /**
     * Refuses a write on a row that was never stored.
     *
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     */
    private function requirePersisted(): void
    {
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('OAuth provider not found for update (id is null)');
        }
    }
}
