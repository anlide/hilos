<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\StepUps as EntityStepUps;
use Hilos\Database\PhpType;

/**
 * StepUp Entity - represents one operation confirmation in hilos_step_up (HIL-495).
 *
 * @method static EntityStepUps get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityStepUps getAll()
 */
final class StepUp extends Entity
{
    public const string id = 'id';
    public const string session_token_hash = 'session_token_hash';
    public const string user_id = 'user_id';
    public const string operation = 'operation';
    public const string confirmed_until = 'confirmed_until';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_step_up';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::session_token_hash,
        self::user_id,
        self::operation,
        self::confirmed_until,
        self::created_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::session_token_hash => PhpType::STRING->value,
        self::user_id => PhpType::INTEGER->value,
        self::operation => PhpType::STRING->value,
        self::confirmed_until => PhpType::DATETIME->value,
        self::created_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_step_up' => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::session_token_hash, self::user_id, self::operation],
        ],
        'idx_step_up_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // A confirmation is a short-lived bearer grant tied to a session token; no row is safe to restore.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public string $session_token_hash;
    public int $user_id;
    public string $operation;
    public string $confirmed_until;
    public string $created_at;
}
