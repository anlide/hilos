<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\AccountOperations as EntityAccountOperations;
use Hilos\Database\PhpType;

/**
 * Durable coordinator working row, independent of live people and deletion requests.
 *
 * @method static EntityAccountOperations get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityAccountOperations getAll()
 */
class AccountOperation extends Entity
{
    public const string INITIAL_STAGE = 'preparing';
    public const int STAGE_MAX_CHARACTERS = 64;
    public const string REQUEST_UNIQUE_KEY = 'uq_account_operation_request';

    public const string id = 'id';
    public const string kind = 'kind';
    public const string request_id = 'request_id';
    public const string root_user_id = 'root_user_id';
    public const string loser_user_id = 'loser_user_id';
    public const string merge_choice = 'merge_choice';
    public const string circle_user_ids = 'circle_user_ids';
    public const string stage = 'stage';
    public const string created_at = 'created_at';
    public const string updated_at = 'updated_at';
    public const string finished_at = 'finished_at';
    public const string open_loser_user_id = 'open_loser_user_id';
    public const string outcome = 'outcome';

    public const string _table = 'hilos_account_operation';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::kind,
        self::request_id,
        self::root_user_id,
        self::loser_user_id,
        self::merge_choice,
        self::circle_user_ids,
        self::stage,
        self::created_at,
        self::updated_at,
        self::finished_at,
        self::open_loser_user_id,
        self::outcome,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::kind => PhpType::STRING->value,
        self::request_id => PhpType::INTEGER->value,
        self::root_user_id => PhpType::INTEGER->value,
        self::loser_user_id => PhpType::INTEGER->value,
        self::merge_choice => PhpType::JSON->value,
        self::circle_user_ids => PhpType::JSON->value,
        self::stage => PhpType::STRING->value,
        self::created_at => PhpType::INTEGER->value,
        self::updated_at => PhpType::INTEGER->value,
        self::finished_at => PhpType::INTEGER->value,
        self::open_loser_user_id => PhpType::INTEGER->value,
        self::outcome => PhpType::STRING->value,
    ];
    public const array _moments = [self::created_at, self::updated_at, self::finished_at];
    public const array _indexes = [
        self::REQUEST_UNIQUE_KEY => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::request_id]],
        'uq_account_operation_open_loser' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::open_loser_user_id]],
        'idx_account_operation_open' => [Entity::INDEX_COLUMNS => [self::kind, self::finished_at, self::id]],
        'idx_account_operation_root' => [Entity::INDEX_COLUMNS => [self::root_user_id, self::id]],
    ];
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = true;
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;
    public const bool _journaled = false;

    public ?int $id = null;
    public string $kind;
    public ?int $request_id = null;
    public int $root_user_id;
    public ?int $loser_user_id = null;
    public ?string $merge_choice = null;
    public string $circle_user_ids;
    public string $stage;
    public int $created_at;
    public int $updated_at;
    public ?int $finished_at = null;
    public ?int $open_loser_user_id = null;
    public ?string $outcome = null;
}
