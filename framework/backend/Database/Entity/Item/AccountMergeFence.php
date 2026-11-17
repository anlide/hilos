<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\AccountMergeFences as EntityAccountMergeFences;
use Hilos\Database\PhpType;

/**
 * Latest nonsecret merge metadata; soft references and tombstones survive business cleanup.
 *
 * @method static EntityAccountMergeFences get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityAccountMergeFences getAll()
 */
class AccountMergeFence extends Entity
{
    public const string collection_key = 'collection_key';
    public const string row_id = 'row_id';
    public const string operation_id = 'operation_id';
    public const string assignment_id = 'assignment_id';
    public const string owner_user_id = 'owner_user_id';
    public const string revision = 'revision';
    public const string deleted = 'deleted';

    public const string _table = 'hilos_account_merge_fence';
    public const array _primary = [self::collection_key, self::row_id];
    public const array _columns = [
        self::collection_key, self::row_id, self::operation_id, self::assignment_id, self::owner_user_id, self::revision, self::deleted,
    ];
    public const array _types = [
        self::collection_key => PhpType::STRING->value,
        self::row_id => PhpType::INTEGER->value,
        self::operation_id => PhpType::INTEGER->value,
        self::assignment_id => PhpType::INTEGER->value,
        self::owner_user_id => PhpType::INTEGER->value,
        self::revision => PhpType::INTEGER->value,
        self::deleted => PhpType::BOOLEAN->value,
    ];
    public const array _indexes = [
        'idx_account_merge_fence_owner' => [Entity::INDEX_COLUMNS => [self::owner_user_id, self::collection_key, self::row_id]],
    ];
    public const string _setVia = self::owner_user_id;
    public const bool _setRoot = true;
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;
    public const bool _journaled = false;

    public string $collection_key;
    public int $row_id;
    public int $operation_id;
    public int $assignment_id;
    public int $owner_user_id;
    public int $revision;
    public bool $deleted;
}
