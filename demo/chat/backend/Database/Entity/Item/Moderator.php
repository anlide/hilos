<?php

namespace Demo\Chat\Database\Entity\Item;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Moderator Entity
 * Auto-generated from table: moderator
 */
final class Moderator extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string name = 'name';
    public const string check_adult_content = 'check_adult_content';
    public const string check_violence = 'check_violence';
    public const string check_profanity = 'check_profanity';
    public const string check_spam = 'check_spam';
    public const string check_hate_speech = 'check_hate_speech';
    public const string sensitivity_level = 'sensitivity_level';
    public const string additional_rules = 'additional_rules';
    public const string active = 'active';

    // Table meta information
    public const string _table = 'moderator';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::name,
        self::check_adult_content,
        self::check_violence,
        self::check_profanity,
        self::check_spam,
        self::check_hate_speech,
        self::sensitivity_level,
        self::additional_rules,
        self::active,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::check_adult_content => PhpType::BOOLEAN->value,
        self::check_violence => PhpType::BOOLEAN->value,
        self::check_profanity => PhpType::BOOLEAN->value,
        self::check_spam => PhpType::BOOLEAN->value,
        self::check_hate_speech => PhpType::BOOLEAN->value,
        self::sensitivity_level => PhpType::INTEGER->value,
        self::additional_rules => PhpType::STRING->value,
        self::active => PhpType::BOOLEAN->value,
    ];

    // Indexes
    public const array _indexes = [
        'active' => ['columns' => [self::active]],
    ];

    // Properties
    public ?int $id = null;
    public string $name;
    public bool $check_adult_content = true;
    public bool $check_violence = true;
    public bool $check_profanity = true;
    public bool $check_spam = true;
    public bool $check_hate_speech = true;
    public int $sensitivity_level = 5;
    public ?string $additional_rules = null;
    public bool $active = true;
}
