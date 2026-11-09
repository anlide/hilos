<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\AccountOperationResults as EntityAccountOperationResults;
use Hilos\Database\PhpType;

/**
 * Immutable participant preparation or result, one per coordinator assignment.
 *
 * @method static EntityAccountOperationResults get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityAccountOperationResults getAll()
 */
class AccountOperationResult extends Entity
{
    public const string ASSIGNMENT_UNIQUE_KEY = 'uq_account_operation_result_assignment';

    public const string id = 'id';
    public const string assignment_id = 'assignment_id';
    public const string owner_agent_id = 'owner_agent_id';
    public const string payload_json = 'payload_json';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_account_operation_result';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::assignment_id, self::owner_agent_id, self::payload_json, self::created_at];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::assignment_id => PhpType::INTEGER->value,
        self::owner_agent_id => PhpType::STRING->value,
        self::payload_json => PhpType::JSON->value,
        self::created_at => PhpType::INTEGER->value,
    ];
    public const array _moments = [self::created_at];
    public const array _foreign = [self::assignment_id => AccountOperationAssignment::_table];
    public const array _indexes = [
        self::ASSIGNMENT_UNIQUE_KEY => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::assignment_id],
        ],
        'idx_account_operation_result_owner' => [Entity::INDEX_COLUMNS => [self::owner_agent_id, self::id]],
    ];
    public const string _setVia = self::owner_agent_id;
    public const bool _setRoot = false;
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;
    public const bool _journaled = false;

    public ?int $id = null;
    public int $assignment_id;
    public string $owner_agent_id;
    public string $payload_json;
    public int $created_at;
}
