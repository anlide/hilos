<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\EventAttachments as EntityEventAttachments;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\File;
use Hilos\Database\PhpType;

/**
 * EventAttachment - Entity representing event_attachment table row.
 *
 * Links one published registry file to the message event it was sent with (HIL-144).
 *
 * @method static EntityEventAttachments get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityEventAttachments getAll()
 */
final class EventAttachment extends Entity
{
    public const string id = 'id';
    public const string event_id = 'event_id';
    public const string file_id = 'file_id';

    public const string _table = 'event_attachment';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::event_id,
        self::file_id,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::event_id => PhpType::INTEGER->value,
        self::file_id => PhpType::INTEGER->value,
    ];

    // No cascade on file_id: the refusal to remove a file an attachment still names is how
    // the files library learns the chat still links it.
    public const array _foreign = [
        self::event_id => EventMessage::_table,
        self::file_id => File::_table,
    ];

    public const array _indexes = [
        'event_id' => [Entity::INDEX_COLUMNS => [self::event_id]],
        'uk_event_attachment_file' => [Entity::INDEX_COLUMNS => [self::file_id], Entity::INDEX_UNIQUE => true],
    ];

    // The column is named event_id, but _foreign hangs it on event_message.
    public const string _setVia = self::event_id;
    public const bool _setRoot = false;

    // Two keys and a link: the name the uploader gave the file lives in hilos_file, which
    // carries its own verdict.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::event_id,
        self::file_id,
    ];

    public ?int $id = null;
    public int $event_id;
    public int $file_id;
}
