<?php

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\ModeratorPromptPiecesActions;
use Demo\Chat\Database\Object\Collection\ModeratorPromptPieces as ObjectModeratorPromptPiecesCollection;
use Demo\Chat\Database\View\Item\ModeratorPromptPiece;
use Hilos\Database\View\Collection\DbCollection;

/**
 * ModeratorPromptPieces - Db collection of ModeratorPromptPiece items.
 *
 * @extends DbCollection<ModeratorPromptPiece, ObjectModeratorPromptPiecesCollection>
 * @method ObjectModeratorPromptPiecesCollection|null getObjectCollection()
 * @method ModeratorPromptPiece|null current()
 * @method ModeratorPromptPiece|null first()
 * @method ModeratorPromptPiece|null last()
 * @method ModeratorPromptPiece|null offsetGet(mixed $offset)
 * @property-read ModeratorPromptPiecesActions $actions Collection actions (create); items use ModeratorPromptPieceActions
 */
final class ModeratorPromptPieces extends DbCollection
{
    public const string DB_ITEM_CLASS = ModeratorPromptPiece::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectModeratorPromptPiecesCollection::class;
}
