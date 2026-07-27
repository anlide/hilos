<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Item\Object_;

/**
 * Notification object - wraps a Notification entity.
 *
 * Exposes the notification fields and the mark-read primitive. `data` is carried
 * as its raw JSON string across the object layer; {@see decodedData()} decodes it
 * for the frontend/signal boundary.
 *
 * @extends Object_<EntityNotification>
 *
 * @property-read ?int $id
 * @property ?int $userId
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property ?string $body
 * @property ?string $data
 * @property ?string $readAt
 * @property-read ?string $createdAt
 */
final class Notification extends Object_
{
    public const string ENTITY_CLASS = EntityNotification::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string type = 'type';
    public const string severity = 'severity';
    public const string title = 'title';
    public const string body = 'body';
    public const string data = 'data';
    public const string readAt = 'readAt';
    public const string createdAt = 'createdAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::notifications)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::notifications;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, userId, type, severity, title, body, data, readAt, createdAt)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known Notification field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::type => $this->entity->type,
            self::severity => $this->entity->severity,
            self::title => $this->entity->title,
            self::body => $this->entity->body,
            self::data => $this->entity->data,
            self::readAt => $this->entity->read_at,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (userId, type, severity, title, body, data, readAt, createdAt)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a Notification
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = $value === null ? null : (int)$value,
            self::type => $this->entity->type = (string)$value,
            self::severity => $this->entity->severity = (string)$value,
            self::title => $this->entity->title = (string)$value,
            self::body => $this->entity->body = $value === null ? null : (string)$value,
            self::data => $this->entity->data = $value === null ? null : (string)$value,
            self::readAt => $this->entity->read_at = $value === null ? null : (string)$value,
            self::createdAt => $this->entity->created_at = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Whether this notification is still unread.
     *
     * @return bool True when read_at has not been stamped
     */
    public function isUnread(): bool
    {
        return $this->entity->read_at === null;
    }

    /**
     * Decodes the structured `data` payload for the frontend/signal boundary.
     *
     * @return ?array<string, mixed> Decoded data, or null when absent or malformed
     */
    public function decodedData(): ?array
    {
        if ($this->entity->data === null || $this->entity->data === '') {
            return null;
        }

        $decoded = json_decode($this->entity->data, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Converts the notification to an associative array (data decoded to structure).
     *
     * @return array<string, mixed> Notification data (id, userId, type, severity, title, body, data, readAt, createdAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::type => $this->entity->type,
            self::severity => $this->entity->severity,
            self::title => $this->entity->title,
            self::body => $this->entity->body,
            self::data => $this->decodedData(),
            self::readAt => $this->entity->read_at,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
