<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\ChangeLogReceipts as EntityChangeLogReceipts;
use Hilos\Database\PhpType;

/**
 * @method static EntityChangeLogReceipts get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityChangeLogReceipts getAll()
 */
class ChangeLogReceipt extends Entity
{
    public const string id = 'id';
    public const string created_at = 'created_at';
    public const string actor_user_id = 'actor_user_id';
    public const string subject_user_id = 'subject_user_id';
    public const string session_id = 'session_id';
    public const string channel = 'channel';
    public const string action = 'action';
    public const string agent = 'agent';
    public const string source = 'source';
    public const string open_handovers = 'open_handovers';

    public const string _table = 'hilos_change_log_receipt';
    public const string _database = DatabaseNames::CHANGE_LOG;
    public const array _primary = [self::id, self::created_at];
    public const array _columns = [
        self::id,
        self::created_at,
        self::actor_user_id,
        self::subject_user_id,
        self::session_id,
        self::channel,
        self::action,
        self::agent,
        self::source,
        self::open_handovers,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::created_at => PhpType::DATETIME->value,
        self::actor_user_id => PhpType::INTEGER->value,
        self::subject_user_id => PhpType::INTEGER->value,
        self::session_id => PhpType::INTEGER->value,
        self::channel => PhpType::STRING->value,
        self::action => PhpType::STRING->value,
        self::agent => PhpType::STRING->value,
        self::source => PhpType::STRING->value,
        self::open_handovers => PhpType::INTEGER->value,
    ];

    public const array _indexes = [
        'idx_hcl_receipt_created' => [
            Entity::INDEX_COLUMNS => [self::created_at, self::id],
        ],
        'idx_hcl_receipt_actor' => [
            Entity::INDEX_COLUMNS => [self::actor_user_id, self::created_at, self::id],
        ],
        'idx_hcl_receipt_channel' => [
            Entity::INDEX_COLUMNS => [self::channel, self::created_at, self::id],
        ],
    ];

    public const bool _journaled = false;
    public const bool _externalWriter = false;
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    public const array _pii = [
        self::actor_user_id => AnonymizationStrategy::NULLIFY,
        self::subject_user_id => AnonymizationStrategy::NULLIFY,
        self::session_id => AnonymizationStrategy::NULLIFY,
        self::agent => AnonymizationStrategy::MASK,
        self::source => AnonymizationStrategy::MASK,
    ];

    public const array _piiNotPersonal = [
        self::id,
        self::created_at,
        self::channel,
        self::action,
        self::open_handovers,
    ];

    public ?int $id = null;
    public string $created_at;
    public ?int $actor_user_id = null;
    public ?int $subject_user_id = null;
    public ?int $session_id = null;
    public string $channel;
    public string $action;
    public ?string $agent = null;
    public ?string $source = null;
    public int $open_handovers = 0;
}
