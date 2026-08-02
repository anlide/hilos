<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\AuthBlocks as EntityAuthBlocks;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * AuthBlock Entity - represents hilos_auth_block table row.
 *
 * Framework anti-abuse durable block (HIL-420): one row per throttled
 * (scope, identity, action) triple records a block that actually fired, so it
 * survives a restart and is visible to every worker. The hot per-window attempt
 * counters live in runtime (RT AuthAttempts) and are never persisted here.
 * Framework holds the contract; projects activate the table thinly (copy the
 * migration stub) and the framework DbContext exposes the collection.
 *
 * The `updated_at` column is DB-maintained (ON UPDATE CURRENT_TIMESTAMP) and,
 * like created/updated columns elsewhere, is intentionally not ORM-mapped.
 *
 * @method static EntityAuthBlocks get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityAuthBlocks getAll()
 */
final class AuthBlock extends Entity
{
    public const string id = 'id';
    public const string scope = 'scope';
    public const string identity = 'identity';
    public const string action = 'action';
    public const string level = 'level';
    public const string blocked_until = 'blocked_until';

    public const string _table = 'hilos_auth_block';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::scope,
        self::identity,
        self::action,
        self::level,
        self::blocked_until,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::scope => PhpType::STRING->value,
        self::identity => PhpType::STRING->value,
        self::action => PhpType::STRING->value,
        self::level => PhpType::INTEGER->value,
        self::blocked_until => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_auth_block_scope_identity_action' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::scope, self::identity, self::action]],
    ];

    public ?int $id = null;
    public string $scope;
    public string $identity;
    public string $action;
    public int $level = 0;
    public ?string $blocked_until = null;
}
