<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\ModeratorPromptPiece as EntityModeratorPromptPiece;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * ModeratorPromptPieces Entity Collection
 *
 * @extends EntityCollection<EntityModeratorPromptPiece>
 * @implements \Iterator<int|string, EntityModeratorPromptPiece>
 * @implements \ArrayAccess<int|string, EntityModeratorPromptPiece>
 */
final class ModeratorPromptPieces extends EntityCollection
{
    public const string ENTITY_CLASS = EntityModeratorPromptPiece::class;
}
