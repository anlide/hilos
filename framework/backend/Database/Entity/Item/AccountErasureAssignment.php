<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\AccountErasureAssignments as EntityAccountErasureAssignments;
use Hilos\Database\PhpType;

/**
 * Durable coordinator working row, independent of live people and deletion requests.
 *
 * @method static EntityAccountErasureAssignments get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityAccountErasureAssignments getAll()
 */
class AccountErasureAssignment extends Entity
{
    public const int ASSIGNMENT_KEY_MAX_CHARACTERS = 191;
    public const string IDENTITY_UNIQUE_KEY = 'uq_account_erasure_assignment';

    public const string id = 'id';
    public const string operation_id = 'operation_id';
    public const string stage = 'stage';
    public const string assignment_key = 'assignment_key';
    public const string owner_type = 'owner_type';
    public const string owner_index = 'owner_index';
    public const string user_id = 'user_id';
    public const string payload_json = 'payload_json';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_account_erasure_assignment';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::operation_id,
        self::stage,
        self::assignment_key,
        self::owner_type,
        self::owner_index,
        self::user_id,
        self::payload_json,
        self::created_at,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::operation_id => PhpType::INTEGER->value,
        self::stage => PhpType::STRING->value,
        self::assignment_key => PhpType::STRING->value,
        self::owner_type => PhpType::STRING->value,
        self::owner_index => PhpType::STRING->value,
        self::user_id => PhpType::INTEGER->value,
        self::payload_json => PhpType::JSON->value,
        self::created_at => PhpType::INTEGER->value,
    ];
    public const array _moments = [self::created_at];
    public const array _foreign = [self::operation_id => AccountErasureOperation::_table];
    public const array _indexes = [
        self::IDENTITY_UNIQUE_KEY => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::operation_id, self::stage, self::assignment_key],
        ],
        'idx_account_erasure_owner' => [Entity::INDEX_COLUMNS => [self::owner_type, self::owner_index, self::id]],
    ];
    public const string _setVia = self::operation_id;
    public const bool _setRoot = false;
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;
    public const bool _journaled = false;

    public ?int $id = null;
    public int $operation_id;
    public string $stage;
    public string $assignment_key;
    public string $owner_type;
    public ?string $owner_index = null;
    public ?int $user_id = null;
    public string $payload_json;
    public int $created_at;
}
