<?php

namespace Demo\Chat\Hilos\Database\Collection;

use Demo\Chat\Database\Object\Collection\ModeratorPromptPieces as ObjectModeratorPromptPiecesCollection;
use Demo\Chat\Hilos\Database\Item\ModeratorPromptPiece;
use Hilos\Hilos\Database\Actions\DbActions;
use Hilos\Hilos\Database\Collection\DbCollection;

/**
 * ModeratorPromptPieces Db collection - collection of ModeratorPromptPiece items.
 *
 * @extends DbCollection<ModeratorPromptPiece, ObjectModeratorPromptPiecesCollection>
 * @method ObjectModeratorPromptPiecesCollection|null getObjectCollection()
 * @method ModeratorPromptPiece|null current()
 * @method ModeratorPromptPiece|null first()
 * @method ModeratorPromptPiece|null last()
 * @method ModeratorPromptPiece|null offsetGet(mixed $offset)
 * @property-read DbActions $actions Actions for write operations
 */
final class ModeratorPromptPieces extends DbCollection
{
    public const string DB_ITEM_CLASS = ModeratorPromptPiece::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectModeratorPromptPiecesCollection::class;
}
