<?php

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Item\ModeratorPromptPiece as EntityModeratorPromptPiece;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * ModeratorPromptPiece Object
 * Auto-generated from Entity: Demo\Chat\Database\Entity\Item\ModeratorPromptPiece
 *
 * @extends Object_<EntityModeratorPromptPiece>
 *
 * @property-read ?int $id
 * @property string $section
 * @property string $promptPiece
 */
final class ModeratorPromptPiece extends Object_
{
    public const string ENTITY_CLASS = EntityModeratorPromptPiece::class;
    public const string SECTION_NAME_RULE = 'name_rule';
    public const string SECTION_MESSAGE_RULE = 'message_rule';

    public const string id = 'id';
    public const string section = 'section';
    public const string promptPiece = 'promptPiece';

    /**
     * Returns the database collection key for this object type.
     *
     * @return string Collection key (DbChatContext::moderatorPromptPieces)
     */
    protected static function getCollectionKey(): string
    {
        return DbChatContext::moderatorPromptPieces;
    }

    /**
     * Returns the value of a moderator prompt piece property by name.
     *
     * @param string $property Property name (id, section, promptPiece)
     * @return mixed Property value or parent method result
     * @throws DatabaseException
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::section => $this->entity->section,
            self::promptPiece => $this->entity->prompt_piece,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a moderator prompt piece property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::section => $this->entity->section = (string)$value,
            self::promptPiece => $this->entity->prompt_piece = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the moderator prompt piece object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::section => $this->entity->section,
            self::promptPiece => $this->entity->prompt_piece,
        ];
    }
}
