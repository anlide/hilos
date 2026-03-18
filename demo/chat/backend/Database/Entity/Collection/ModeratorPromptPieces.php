<?php

namespace Demo\Chat\Database\Entity\Collection;

use ArrayAccess;
use Demo\Chat\Database\Entity\Item\ModeratorPromptPiece as EntityModeratorPromptPiece;
use Hilos\Database\Entity\Collection\EntityCollection;
use Iterator;

/**
 * ModeratorPromptPieces - Entity collection for moderator prompt pieces.
 *
 * @extends EntityCollection<EntityModeratorPromptPiece>
 * @implements Iterator<int|string, EntityModeratorPromptPiece>
 * @implements ArrayAccess<int|string, EntityModeratorPromptPiece>
 */
final class ModeratorPromptPieces extends EntityCollection
{
    public const string ENTITY_CLASS = EntityModeratorPromptPiece::class;
}
