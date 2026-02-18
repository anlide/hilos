<?php

namespace Demo\Chat\Database\Entity\Item;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Bot Entity
 * Auto-generated from table: bot
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
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::description => PhpType::STRING->value,
        self::style => PhpType::STRING->value,
        self::topics => PhpType::STRING->value,
        self::personality => PhpType::STRING->value,
        self::active => PhpType::BOOLEAN->value,
    ];

    // Indexes
    public const array _indexes = [
        'active' => ['columns' => [self::active]],
    ];

    // Properties
    public ?int $id = null;
    public string $name;
    public ?string $description = null;
    public ?string $style = null;
    public ?string $topics = null;
    public ?string $personality = null;
    public bool $active = true;
}
