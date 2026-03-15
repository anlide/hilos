<?php

declare(strict_types=1);

namespace Demo\Chat\Tables;

use Demo\Chat\Tables\Bot\BotsTable;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Demo\Chat\Tables\User\UsersTable;
use Hilos\Core\Table\Context\TableContext;

/**
 * App-specific table context ($table layer).
 *
 * Registers users, bots, and moderator prompt pieces tables.
 * Accessed via Hilos::$table->users, Hilos::$table->bots, etc.
 *
 * @property-read UsersTable $users
 * @property-read BotsTable $bots
 * @property-read ModeratorPromptPiecesTable $moderatorPromptPieces
 */
final class TableChatContext extends TableContext
{
    public const string users = 'users';
    public const string bots = 'bots';
    public const string moderatorPromptPieces = 'moderatorPromptPieces';

    /**
     * Registers all chat tables with their data sources.
     */
    public function configure(): void
    {
        $this->register(self::users, new UsersTable());
        $this->register(self::bots, new BotsTable());
        $this->register(self::moderatorPromptPieces, new ModeratorPromptPiecesTable());
    }
}
