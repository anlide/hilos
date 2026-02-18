<?php

namespace Demo\Chat\Database\Entity\Item;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * ModeratorPromptPiece Entity
 * Auto-generated from table: moderator_prompt_piece
 */
final class ModeratorPromptPiece extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string section = 'section';
    public const string prompt_piece = 'prompt_piece';

    // Section enum values
    public const string SECTION_NAME_RULE = 'name_rule';
    public const string SECTION_MESSAGE_RULE = 'message_rule';

    // Table meta information
    public const string _table = 'moderator_prompt_piece';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::section,
        self::prompt_piece,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::section => PhpType::STRING->value,
        self::prompt_piece => PhpType::STRING->value,
    ];

    // Properties
    public ?int $id = null;
    public string $section;
    public string $prompt_piece;
}
