<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;

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
final class Identity extends DbItem
{
    /**
     * Magic getter for identity properties.
     *
     * @param string $name Property name (id, userId, type, identifier, provider, verified)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
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
}
