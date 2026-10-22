<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\ModerationRules as EntityModerationRules;
use Hilos\Database\PhpType;

/**
 * An administrator's own rule for one moderation kind (HIL-1624).
 *
 * The kind is the string key of the moderation kind directory; a kind's own rules replace its
 * built-in rules whole while at least one of them exists.
 *
 * @method static EntityModerationRules get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityModerationRules getAll()
 */
class ModerationRule extends Entity
{
    public const string id = 'id';
    public const string kind = 'kind';
    public const string rule = 'rule';

    public const string _table = 'hilos_moderation_rule';
    public const bool _journaled = true;
    public const string _primary = self::id;
    public const array _columns = [self::id, self::kind, self::rule];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::kind => PhpType::STRING->value,
        self::rule => PhpType::TEXT->value,
    ];
    public const array _indexes = [
        'idx_moderation_rule_kind' => [Entity::INDEX_COLUMNS => [self::kind]],
    ];

    // The installation's rules are cut by nobody's key, and nothing hangs a set off them.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    // A rule is an administrator's policy text for a kind, not data about a person.
    public const array _pii = [];
    public const array _piiNotPersonal = [self::id, self::kind, self::rule];

    public ?int $id = null;
    public string $kind;
    public string $rule;
}
