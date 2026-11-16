<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\AccountMergeCopies as EntityAccountMergeCopies;
use Hilos\Database\PhpType;

/**
 * Immutable secret-bearing merge copy, keyed by its loser's give assignment.
 * The payload is DB-only and never enters ORM hydration or cross-worker sync.
 *
 * @object-exclude payload_json
 * @method static EntityAccountMergeCopies get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityAccountMergeCopies getAll()
 */
class AccountMergeCopy extends Entity
{
    public const string assignment_id = 'assignment_id';
    public const string owner_agent_id = 'owner_agent_id';
    public const string payload_json = 'payload_json';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_account_merge_copy';
    public const string _primary = self::assignment_id;
    public const array _columns = [self::assignment_id, self::owner_agent_id, self::created_at];
    public const array _types = [
        self::assignment_id => PhpType::INTEGER->value,
        self::owner_agent_id => PhpType::STRING->value,
        self::created_at => PhpType::INTEGER->value,
    ];
    public const array _moments = [self::created_at];
    public const array _foreign = [self::assignment_id => AccountOperationAssignment::_table];
    public const array _indexes = [
        'idx_account_merge_copy_owner' => [Entity::INDEX_COLUMNS => [self::owner_agent_id, self::assignment_id]],
    ];
    public const string _setVia = self::owner_agent_id;
    public const bool _setRoot = false;
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;
    public const bool _journaled = false;

    public int $assignment_id;
    public string $owner_agent_id;
    public int $created_at;
}
