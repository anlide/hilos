<?php

namespace Demo\WebSocketTest\Database\Entity;

use Hilos\Database\Entity\Entity;
use Hilos\Database\PhpType;

/**
 * Bot Entity
 * Auto-generated from table: bot
 */
final class Bot extends Entity
{
    // Column name constants
    public const string id_bot = 'id_bot';
    public const string name = 'name';
    public const string description = 'description';
    public const string style = 'style';
    public const string topics = 'topics';
    public const string personality = 'personality';
    public const string active = 'active';
    public const string created_at = 'created_at';
    public const string updated_at = 'updated_at';

    // Table meta information
    public const string _table = 'bot';
    public const string _primary = self::id_bot;
    public const array _columns = [
        self::id_bot,
        self::name,
        self::description,
        self::style,
        self::topics,
        self::personality,
        self::active,
        self::created_at,
        self::updated_at,
    ];

    // Column types
    public const array _types = [
        self::id_bot => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::description => PhpType::STRING->value,
        self::style => PhpType::STRING->value,
        self::topics => PhpType::STRING->value,
        self::personality => PhpType::STRING->value,
        self::active => PhpType::BOOLEAN->value,
        self::created_at => PhpType::DATETIME->value,
        self::updated_at => PhpType::DATETIME->value,
    ];

    // Indexes
    public const array _indexes = [
        'active' => ['columns' => [self::active]],
    ];

    // Properties
    public ?int $id_bot = null;
    public string $name;
    public ?string $description = null;
    public ?string $style = null;
    public ?string $topics = null;
    public ?string $personality = null;
    public bool $active = true;
    public string $created_at = 'current_timestamp()';
    public string $updated_at = 'current_timestamp()';
}
