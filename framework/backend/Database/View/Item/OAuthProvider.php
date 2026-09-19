<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\OAuthProviderActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\OAuthProvider as ObjectOAuthProvider;
use Hilos\HilosException;

/**
 * OAuthProvider Db item - read-only wrapper around ObjectOAuthProvider (HIL-286).
 *
 * The secret has no property here: only its set/not-set state is readable
 * ({@see hasClientSecret()}), and the value itself is read by the resolver alone
 * ({@see readClientSecret()}).
 *
 * @extends DbItem<ObjectOAuthProvider>
 * @property-read ?int $id
 * @property-read string $providerKey
 * @property-read ?string $clientId
 * @property-read ?string $scope
 * @property-read OAuthProviderActions $actions
 */
final class OAuthProvider extends DbItem
{
    /**
     * Magic getter for entity properties.
     *
     * @param string $name Property name (id, providerKey, clientId, scope)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectOAuthProvider::id => $this->_object->id,
            ObjectOAuthProvider::providerKey => $this->_object->providerKey,
            ObjectOAuthProvider::clientId => $this->_object->clientId,
            ObjectOAuthProvider::scope => $this->_object->scope,
            default => parent::__get($name),
        };
    }

    /**
     * Reports whether this provider row carries a non-empty client secret.
     *
     * @return bool True when a secret is stored
     * @throws DatabaseException When the secret lookup query fails
     */
    public function hasClientSecret(): bool
    {
        return $this->_object->hasClientSecret();
    }

    /**
     * Reads the stored client secret for building the provider's live configuration.
     *
     * @return ?string Stored secret, or null when none is stored
     * @throws DatabaseException When the secret lookup query fails
     */
    public function readClientSecret(): ?string
    {
        return $this->_object->readClientSecret();
    }
}
