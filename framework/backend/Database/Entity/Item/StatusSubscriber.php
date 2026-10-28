<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\StatusSubscribers as EntityStatusSubscribers;
use Hilos\Database\PhpType;
use Hilos\Database\SqlSortDirection;

/**
 * Independent email subscriptions; secrets never enter ORM hydration or DB sync.
 *
 * @object-exclude confirmation_token_hash
 * @object-exclude unsubscribe_token
 * @method static EntityStatusSubscribers get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityStatusSubscribers getAll()
 */
class StatusSubscriber extends Entity
{
    public const string id = 'id';
    public const string email = 'email';
    public const string subscribed_at = 'subscribed_at';
    public const string confirmed = 'confirmed';
    public const string confirmed_at = 'confirmed_at';
    public const string confirmation_expires_at = 'confirmation_expires_at';
    public const string browser_time_zone = 'browser_time_zone';
    public const string confirmation_token_hash = 'confirmation_token_hash';
    public const string unsubscribe_token = 'unsubscribe_token';

    public const string _table = 'hilos_status_subscriber';
    public const string _database = DatabaseNames::PRIMARY;
    public const bool _journaled = false;
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::email,
        self::subscribed_at,
        self::confirmed,
        self::confirmed_at,
        self::confirmation_expires_at,
        self::browser_time_zone,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::email => PhpType::STRING->value,
        self::subscribed_at => PhpType::INTEGER->value,
        self::confirmed => PhpType::BOOLEAN->value,
        self::confirmed_at => PhpType::INTEGER->value,
        self::confirmation_expires_at => PhpType::INTEGER->value,
        self::browser_time_zone => PhpType::STRING->value,
    ];
    public const array _foreign = [];
    public const array _indexes = [
        'uk_status_subscriber_email' => [
            Entity::INDEX_COLUMNS => [
                self::email,
            ],
            Entity::INDEX_UNIQUE => true,
        ],
        'uk_status_subscriber_confirmation_token' => [
            Entity::INDEX_COLUMNS => [
                self::confirmation_token_hash,
            ],
            Entity::INDEX_UNIQUE => true,
        ],
        'uk_status_subscriber_unsubscribe_token' => [
            Entity::INDEX_COLUMNS => [
                self::unsubscribe_token,
            ],
            Entity::INDEX_UNIQUE => true,
        ],
        'idx_status_subscriber_subscribed' => [
            Entity::INDEX_COLUMNS => [
                [Entity::INDEX_COLUMN => self::subscribed_at, Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
                [Entity::INDEX_COLUMN => self::id, Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
            ],
        ],
        'idx_status_subscriber_email' => [
            Entity::INDEX_COLUMNS => [
                self::email,
                self::id,
            ],
        ],
        'idx_status_subscriber_confirmed' => [
            Entity::INDEX_COLUMNS => [
                self::confirmed,
                self::id,
            ],
        ],
        'idx_status_subscriber_confirmed_subscribed' => [
            Entity::INDEX_COLUMNS => [
                self::confirmed,
                [Entity::INDEX_COLUMN => self::subscribed_at, Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
                [Entity::INDEX_COLUMN => self::id, Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
            ],
        ],
        'idx_status_subscriber_confirmed_email' => [
            Entity::INDEX_COLUMNS => [
                self::confirmed,
                self::email,
                self::id,
            ],
        ],
    ];
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;
    public const array _pii = [
        self::email => AnonymizationStrategy::FAKE_EMAIL,
        self::confirmation_token_hash => AnonymizationStrategy::NULLIFY,
        self::unsubscribe_token => AnonymizationStrategy::NULLIFY,
        self::browser_time_zone => AnonymizationStrategy::NULLIFY,
    ];
    public const array _piiNotPersonal = [
        self::id,
        self::subscribed_at,
        self::confirmed,
        self::confirmed_at,
        self::confirmation_expires_at,
    ];

    public ?int $id = null;
    public string $email;
    public int $subscribed_at;
    public bool $confirmed;
    public ?int $confirmed_at = null;
    public ?int $confirmation_expires_at = null;
    public ?string $browser_time_zone = null;
}
