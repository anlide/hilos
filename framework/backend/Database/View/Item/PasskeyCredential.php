<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\PasskeyCredential as ObjectPasskeyCredential;
use Hilos\HilosException;

/**
 * PasskeyCredential Db item - read-only wrapper around ObjectPasskeyCredential.
 *
 * The passkey ceremonies are backend-only; this wrapper exists so the collection
 * has a DbItem representation. It surfaces only the display fields (HIL-404) — the
 * public key and user handle stay internal to the object layer. The browser
 * projection of the profile's key list reads these very properties (HIL-418), so
 * a field the list shows has to be readable here.
 *
 * @extends DbItem<ObjectPasskeyCredential>
 * @property-read ?int $id
 * @property-read int $identityId
 * @property-read int $userId
 * @property-read string $credentialId
 * @property-read ?string $transports
 * @property-read ?string $aaguid
 * @property-read ?string $label
 * @property-read ?string $lastUsedAt
 * @property-read string $createdAt
 */
final class PasskeyCredential extends DbItem
{
    /**
     * Magic getter for credential properties.
     *
     * @param string $name Property name (id, identityId, userId, credentialId, transports, aaguid, label, lastUsedAt, createdAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectPasskeyCredential::id => $this->_object->id,
            ObjectPasskeyCredential::identityId => $this->_object->identityId,
            ObjectPasskeyCredential::userId => $this->_object->userId,
            ObjectPasskeyCredential::credentialId => $this->_object->credentialId,
            ObjectPasskeyCredential::transports => $this->_object->transports,
            ObjectPasskeyCredential::aaguid => $this->_object->aaguid,
            ObjectPasskeyCredential::label => $this->_object->label,
            ObjectPasskeyCredential::lastUsedAt => $this->_object->lastUsedAt,
            ObjectPasskeyCredential::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }
}
