<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\HilosException;

/**
 * Identity Db item - read-only wrapper around ObjectIdentity.
 *
 * @extends DbItem<ObjectIdentity>
 * @property-read ?int $id
 * @property-read ?int $userId
 * @property-read string $type
 * @property-read string $identifier
 * @property-read ?string $provider
 * @property-read bool $verified
 */
class Identity extends DbItem
{
    /**
     * Magic getter for identity properties.
     *
     * @param string $name Property name (id, userId, type, identifier, provider, verified)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectIdentity::id => $this->_object->id,
            ObjectIdentity::userId => $this->_object->userId,
            ObjectIdentity::type => $this->_object->type,
            ObjectIdentity::identifier => $this->_object->identifier,
            ObjectIdentity::provider => $this->_object->provider,
            ObjectIdentity::verified => $this->_object->verified,
            default => parent::__get($name),
        };
    }

    /**
     * Verifies a plaintext secret against this identity's stored hash.
     *
     * Delegates to the object layer's verify primitive; only the boolean
     * result is exposed, never the hash.
     *
     * @param string $plainPassword Plaintext secret to check
     * @return bool True when the secret matches the stored hash
     * @throws DatabaseException When the secret lookup query fails
     */
    public function verifyPassword(string $plainPassword): bool
    {
        return $this->_object->verifyPassword($plainPassword);
    }

    /**
     * Tells whether the stored hash was written under parameters that are no longer current.
     *
     * Delegates to the object layer's rehash check; the hash never crosses the view boundary.
     *
     * @return bool True when the stored hash should be minted again
     * @throws DatabaseException When the secret lookup query fails
     */
    public function passwordNeedsRehash(): bool
    {
        return $this->_object->passwordNeedsRehash();
    }

    /**
     * Stores a password hash minted elsewhere as this identity's secret (HIL-1405).
     *
     * Delegates to the object layer's secret-update primitive; the person's agent writes through
     * it, and the hash it is given never crosses back.
     *
     * @param string $passwordHash `password_hash()` value to store as the secret
     * @throws DatabaseException When the secret update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function setPasswordHash(string $passwordHash): void
    {
        $this->_object->setPasswordHash($passwordHash);
    }

    /**
     * Marks this identity verified (register-confirm write path).
     *
     * Delegates to the object layer's verify-flip primitive, which announces the flip to
     * every reader of the row (HIL-299).
     *
     * @throws DatabaseException When the verified update query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws CreateNotAllowedException When the sync would add the row rather than update it, and nothing here may
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function markVerified(): void
    {
        $this->_object->markVerified();
    }
}
