<?php

declare(strict_types=1);

namespace Demo\Chat\Tables;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\BotsTable;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Demo\Chat\Tables\User\UsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DataSource\EntityTableDataSource;

/**
 * App-specific table context ($table layer).
 *
 * @property-read UsersTable $users
 * @property-read BotsTable $bots
 * @property-read ModeratorPromptPiecesTable $moderatorPromptPieces
 */
class TableChatContext extends TableContext
{
    public const string users = 'users';
    public const string bots = 'bots';
    public const string moderatorPromptPieces = 'moderatorPromptPieces';

    public function configure(): void
    {
        $this->register(self::users, new UsersTable(
            new EntityTableDataSource(Hilos::$db->users),
        ));
        $this->register(self::bots, new BotsTable(
            new EntityTableDataSource(Hilos::$db->bots),
        ));
        $this->register(self::moderatorPromptPieces, new ModeratorPromptPiecesTable(
            new EntityTableDataSource(Hilos::$db->moderatorPromptPieces),
        ));
    }
}
