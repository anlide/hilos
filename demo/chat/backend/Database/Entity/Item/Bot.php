<?php

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\Bots as EntityBots;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Bot - Entity representing bot table row.
 *
 * Auto-generated from table: bot.
 * Stores bot configuration (name, personality, reaction settings).
 *
 * @method static EntityBots get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityBots getAll()
 */
final class Bot extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string name = 'name';
    public const string description = 'description';
    public const string style = 'style';
    public const string topics = 'topics';
    public const string personality = 'personality';
    public const string active = 'active';
    public const string reaction_delay_min = 'reaction_delay_min';
    public const string reaction_delay_max = 'reaction_delay_max';
    public const string reaction_chance = 'reaction_chance';
    public const string topic_match_required = 'topic_match_required';
    public const string cooldown_after_message = 'cooldown_after_message';
    public const string priority = 'priority';

    // Table meta information
    public const string _table = 'bot';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::name,
        self::description,
        self::style,
        self::topics,
        self::personality,
        self::active,
        self::reaction_delay_min,
        self::reaction_delay_max,
        self::reaction_chance,
        self::topic_match_required,
        self::cooldown_after_message,
        self::priority,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::description => PhpType::TEXT->value,
        self::style => PhpType::STRING->value,
        self::topics => PhpType::TEXT->value,
        self::personality => PhpType::TEXT->value,
        self::active => PhpType::BOOLEAN->value,
        self::reaction_delay_min => PhpType::INTEGER->value,
        self::reaction_delay_max => PhpType::INTEGER->value,
        self::reaction_chance => PhpType::INTEGER->value,
        self::topic_match_required => PhpType::BOOLEAN->value,
        self::cooldown_after_message => PhpType::INTEGER->value,
        self::priority => PhpType::INTEGER->value,
    ];

    // Indexes
    public const array _indexes = [
        'active' => [Entity::INDEX_COLUMNS => [self::active]],
    ];

    // A bot is written by the installation, not by a person, and is the same for everyone.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::name,
        self::description,
        self::style,
        self::topics,
        self::personality,
        self::active,
        self::reaction_delay_min,
        self::reaction_delay_max,
        self::reaction_chance,
        self::topic_match_required,
        self::cooldown_after_message,
        self::priority,
    ];

    // Properties
    public ?int $id = null;
    public string $name;
    public ?string $description = null;
    public ?string $style = null;
    public ?string $topics = null;
    public ?string $personality = null;
    public bool $active = true;
    public int $reaction_delay_min = 5;
    public int $reaction_delay_max = 30;
    public int $reaction_chance = 80;
    public bool $topic_match_required = true;
    public int $cooldown_after_message = 60;
    public int $priority = 0;
}
