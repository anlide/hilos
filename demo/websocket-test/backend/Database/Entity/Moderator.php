<?php

namespace Demo\WebSocketTest\Database\Entity;

use Hilos\Database\Entity\Entity;
use Hilos\Database\PhpType;

/**
 * Moderator Entity
 * Auto-generated from table: moderator
 */
final class Moderator extends Entity
{
    // Column name constants
    public const string id_moderator = 'id_moderator';
    public const string name = 'name';
    public const string check_adult_content = 'check_adult_content';
    public const string check_violence = 'check_violence';
    public const string check_profanity = 'check_profanity';
    public const string check_spam = 'check_spam';
    public const string check_hate_speech = 'check_hate_speech';
    public const string sensitivity_level = 'sensitivity_level';
    public const string additional_rules = 'additional_rules';
    public const string active = 'active';
    public const string created_at = 'created_at';
    public const string updated_at = 'updated_at';

    // Table meta information
    public const string _table = 'moderator';
    public const string _primary = self::id_moderator;
    public const array _columns = [
        self::id_moderator,
        self::name,
        self::check_adult_content,
        self::check_violence,
        self::check_profanity,
        self::check_spam,
        self::check_hate_speech,
        self::sensitivity_level,
        self::additional_rules,
        self::active,
        self::created_at,
        self::updated_at,
    ];

    // Column types
    public const array _types = [
        self::id_moderator => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::check_adult_content => PhpType::BOOLEAN->value,
        self::check_violence => PhpType::BOOLEAN->value,
        self::check_profanity => PhpType::BOOLEAN->value,
        self::check_spam => PhpType::BOOLEAN->value,
        self::check_hate_speech => PhpType::BOOLEAN->value,
        self::sensitivity_level => PhpType::INTEGER->value,
        self::additional_rules => PhpType::STRING->value,
        self::active => PhpType::BOOLEAN->value,
        self::created_at => PhpType::DATETIME->value,
        self::updated_at => PhpType::DATETIME->value,
    ];

    // Indexes
    public const array _indexes = [
        'active' => ['columns' => [self::active]],
    ];

    // Properties
    public ?int $id_moderator = null;
    public string $name;
    public bool $check_adult_content = true;
    public bool $check_violence = true;
    public bool $check_profanity = true;
    public bool $check_spam = true;
    public bool $check_hate_speech = true;
    public int $sensitivity_level = 5;
    public ?string $additional_rules = null;
    public bool $active = true;
    public string $created_at = 'current_timestamp()';
    public string $updated_at = 'current_timestamp()';
}
