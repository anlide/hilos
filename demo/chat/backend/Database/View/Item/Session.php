<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\Actions\Item\SessionActions;
use Demo\Chat\Database\Object\Item\Session as ObjectSession;
use Hilos\Database\View\Item\DbItem;

/**
 * Session - Db item with high-level abstraction and lazy loading.
 *
 * Stores reference to ObjectSession instance.
 *
 * @extends DbItem<ObjectSession>
 * @method __construct(ObjectSession &$objectSession)
 *
 * @property-read ?int $id Session ID (primary key)
 * @property-read string $token Session cookie token
 * @property-read ?int $userId Bound user id, or null when the session is anonymous
 * @property-read ?string $createdAt Creation timestamp
 * @property-read ?string $lastSeenAt Last-seen timestamp
 * @property-read ?string $expiresAt Expiry timestamp, or null when open-ended
 * @property-read SessionActions $actions Actions for write operations on this session
 */
final class Session extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return int|string|SessionActions|null Property value or actions
     */
    public function __get(string $name): int|string|SessionActions|null
    {
        return match ($name) {
            ObjectSession::id => $this->_object->id,
            ObjectSession::token => $this->_object->token,
            ObjectSession::userId => $this->_object->userId,
            ObjectSession::createdAt => $this->_object->createdAt,
            ObjectSession::lastSeenAt => $this->_object->lastSeenAt,
            ObjectSession::expiresAt => $this->_object->expiresAt,
            default => parent::__get($name),
        };
    }
}
