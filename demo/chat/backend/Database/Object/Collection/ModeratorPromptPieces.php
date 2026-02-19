<?php

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Collection\ModeratorPromptPieces as EntityModeratorPromptPieces;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Hilos\Database\Object\Objects;

/**
 * ModeratorPromptPieces Object Collection
 *
 * @extends Objects<ObjectModeratorPromptPiece>
 */
final class ModeratorPromptPieces extends Objects
{
    public const string OBJECT_CLASS = ObjectModeratorPromptPiece::class;
    public const string ENTITY_COLLECTION_CLASS = EntityModeratorPromptPieces::class;
    public const string COLLECTION_KEY = DbChatContext::moderatorPromptPieces;
}
