<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Demo\Chat\Database\Entity\Collection\EventMessages as EntityEventMessages;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * EventMessage - Entity representing event_message table row.
 *
 * Stores scalar payload for message_sent chat events.
 *
 * @method static EntityEventMessages get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityEventMessages getAll()
 */
final class EventMessage extends Entity
{
    public const string event_id = 'event_id';
    public const string author_user_id = 'author_user_id';
    public const string author_bot_id = 'author_bot_id';
    public const string message = 'message';

    public const string _table = 'event_message';
    public const string _primary = self::event_id;
    public const array _columns = [
        self::event_id,
        self::author_user_id,
        self::author_bot_id,
        self::message,
    ];

    public const array _types = [
        self::event_id => PhpType::INTEGER->value,
        self::author_user_id => PhpType::INTEGER->value,
        self::author_bot_id => PhpType::INTEGER->value,
        self::message => PhpType::TEXT->value,
    ];

    public const array _foreign = [
        self::event_id => Event::_table,
        self::author_user_id => User::_table,
        self::author_bot_id => Bot::_table,
    ];

    public const array _indexes = [
        'author_user_id' => [Entity::INDEX_COLUMNS => [self::author_user_id]],
        'author_bot_id' => [Entity::INDEX_COLUMNS => [self::author_bot_id]],
    ];

    // _foreign names three tables, and the owner is the one the table name carries: the event.
    // event_attachment in turn hangs its set on this one.
    public const string _setVia = self::event_id;
    public const bool _setRoot = true;

    // What somebody typed into the chat.
    public const array _pii = [self::message => AnonymizationStrategy::MASK];

    public const array _piiNotPersonal = [
        self::event_id,
        self::author_user_id,
        self::author_bot_id,
    ];

    public int $event_id;
    public ?int $author_user_id = null;
    public ?int $author_bot_id = null;
    public string $message = '';
}
