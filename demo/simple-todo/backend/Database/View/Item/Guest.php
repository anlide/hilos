<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Database\View\Item;

use Demo\SimpleTodo\Database\Object\Item\Guest as ObjectGuest;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * Guest - Db item with high-level abstraction and lazy loading.
 *
 * Stores reference to ObjectGuest instance.
 * Object instances are stored in ObjectCollection in Hilos.
 *
 * Read-only by design: nobody edits a guest row. The name is minted once by the
 * handshake and the row is dropped whole when the session gains an account
 * (HIL-610), so the item carries no actions.
 *
 * @extends DbItem<ObjectGuest>
 * @method __construct(ObjectGuest &$objectGuest)
 *
 * @property-read ?int $id Guest ID (primary key)
 * @property-read string $sessionToken Session cookie token this name belongs to
 * @property-read string $name Display name shown to the visitor
 * @property-read string $createdAt Moment the row was minted
 */
final class Guest extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return int|string|null Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException Always, for `actions`: a guest row is never written through an item
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): int|string|null
    {
        return match ($name) {
            ObjectGuest::id => $this->_object->id,
            ObjectGuest::sessionToken => $this->_object->sessionToken,
            ObjectGuest::name => $this->_object->name,
            ObjectGuest::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }
}
