<?php

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\Entity\Item\ModeratorPromptPiece as EntityModeratorPromptPiece;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * ModeratorPromptPiece Object
 * Auto-generated from Entity: Demo\Chat\Database\Entity\Item\ModeratorPromptPiece
 *
 * @property-read ?int $id
 * @property string $section
 * @property string $promptPiece
 */
final class ModeratorPromptPiece extends Object_
{
    public const string id = 'id';
    public const string section = 'section';
    public const string promptPiece = 'promptPiece';

    protected EntityModeratorPromptPiece $entity;
    protected EntityModeratorPromptPiece $entitySync;

    public static function create(): self
    {
        $obj = new self();
        $obj->entity = EntityModeratorPromptPiece::getEmpty();
        $obj->entitySync = clone $obj->entity;
        return $obj;
    }

    public static function fromEntity(EntityModeratorPromptPiece $entity): self
    {
        $obj = new self();
        $obj->entity = $entity;
        $obj->entitySync = clone $entity;
        return $obj;
    }

    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::section => $this->entity->section,
            self::promptPiece => $this->entity->prompt_piece,
            default => parent::__get($property),
        };
    }

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::section => $this->entity->section = (string)$value,
            self::promptPiece => $this->entity->prompt_piece = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::section => $this->entity->section,
            self::promptPiece => $this->entity->prompt_piece,
        ];
    }

    public function getIdString(): string
    {
        if ($this->entity->id === null) {
            throw new DatabaseException("Cannot get ID string: ModeratorPromptPiece ID is null");
        }
        return (string)$this->entity->id;
    }
}
