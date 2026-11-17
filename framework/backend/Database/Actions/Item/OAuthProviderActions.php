<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Auth\OAuth\RawOAuthProviderSnapshot;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\OAuthProvider as ObjectOAuthProvider;
use Hilos\Database\View\Item\OAuthProvider;
use Hilos\Environment\NonProductionGate;

/**
 * OAuthProviderActions - write operations for one OAuth provider row (HIL-286).
 *
 * One write per field. A null value takes the field out of the admin layer, and the
 * provider falls back to its env value and then to its recipe for it.
 *
 * @extends DbActions<OAuthProvider, ObjectOAuthProvider>
 * @property-read ObjectOAuthProvider $object
 */
class OAuthProviderActions extends DbActions
{
    /**
     * Updates the client id the administrator entered, or clears it with null.
     *
     * @param ?string $clientId New client id, or null to fall back to env
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws DatabaseException When collection loading or row persistence fails
     * @throws ObjectGetIdStringNotImplementedException When the primary key is null during the per-item write check
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
     * @throws WriteNotAllowedException When the truth source rejects the row write
     * @throws CreateNotAllowedException Never for a persisted row; declared by the item door for a row not stored yet
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function writeClientSecret(?string $secret): void
    {
        $this->ensureCanWrite();
        $this->requirePersisted();

        $this->object->writeClientSecret($secret);
    }

    /**
     * @param RawOAuthProviderSnapshot $snapshot Original state of this same item
     * @throws InvalidFormatException When the non-production gate or snapshot identity refuses
     * @throws ObjectCollectionNullException When detached from the object collection
     * @throws DatabaseException When persistence fails
     * @throws ObjectGetIdStringNotImplementedException When the primary id is unavailable
     * @throws WriteNotAllowedException When the owner refuses the write
     * @throws CreateNotAllowedException Never for a persisted row; declared by the item door
     * @throws InvalidArgumentException When the sync signal cannot be named
     * @throws SourceChangeSubscriberException When an announcement subscriber refuses
     */
    public function restoreRawForTest(RawOAuthProviderSnapshot $snapshot): void
    {
        if (!NonProductionGate::admitted()) {
            throw new InvalidFormatException('Raw OAuth provider restoration is test-only.');
        }
        $this->ensureCanWrite(TruthSourceOperation::Update);
        $this->object->restoreRawForTest($snapshot);
    }

    /**
     * Deletes the already selected test row, including its collection membership.
     *
     * @throws InvalidFormatException When the non-production gate or snapshot identity refuses
     * @throws ObjectCollectionNullException When detached from the object collection
     * @throws DatabaseException When persistence fails
     * @throws ObjectGetIdStringNotImplementedException When the primary id is unavailable
     * @throws WriteNotAllowedException When the owner refuses the write
     * @throws CreateNotAllowedException Never for a persisted row; declared by the item door
     * @throws InvalidArgumentException When the sync signal cannot be named
     * @throws SourceChangeSubscriberException When an announcement subscriber refuses
     * @throws ItemNotFoundForUpdateException When the selected row is not persisted
     * @throws LogicException When the collection class is not configured
     */
    public function deleteForTest(): void
    {
        if (!NonProductionGate::admitted()) {
            throw new InvalidFormatException('OAuth provider test deletion is test-only.');
        }
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->requirePersisted();
        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');
        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
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
