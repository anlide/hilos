<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\EventAttachments as EntityEventAttachments;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * EventAttachment - Entity representing event_attachment table row.
 *
 * Stores published chat attachment metadata linked to one event.
 *
 * @method static EntityEventAttachments get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityEventAttachments getAll()
 */
final class EventAttachment extends Entity
{
    public const string id = 'id';
    public const string event_id = 'event_id';
    public const string filename = 'filename';
    public const string mime_type = 'mime_type';
    public const string stored_name = 'stored_name';

    public const string _table = 'event_attachment';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::event_id,
        self::filename,
        self::mime_type,
        self::stored_name,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::event_id => PhpType::INTEGER->value,
        self::filename => PhpType::STRING->value,
        self::mime_type => PhpType::STRING->value,
        self::stored_name => PhpType::STRING->value,
    ];

    public const array _foreign = [
        self::event_id => Event::_table,
    ];

    public const array _indexes = [
        'event_id' => [Entity::INDEX_COLUMNS => [self::event_id]],
        'stored_name' => [Entity::INDEX_COLUMNS => [self::stored_name], Entity::INDEX_UNIQUE => true],
    ];

    public ?int $id = null;
    public int $event_id;
    public string $filename;
    public string $mime_type;
    public string $stored_name;
}
