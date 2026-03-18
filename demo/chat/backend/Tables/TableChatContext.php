<?php

declare(strict_types=1);

namespace Demo\Chat\Tables;

use Demo\Chat\Tables\Bot\BotsTable;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Demo\Chat\Tables\Settings\SettingsTable;
use Demo\Chat\Tables\User\UsersTable;
use Hilos\Core\Table\Context\TableContext;

/**
 * TableChatContext - App-specific table context ($table layer).
 *
 * Registers users, bots, moderator prompt pieces, and settings tables.
 * Accessed via Hilos::$table->users, Hilos::$table->bots, etc.
 *
 * @property-read UsersTable $users
 * @property-read BotsTable $bots
 * @property-read ModeratorPromptPiecesTable $moderatorPromptPieces
 * @property-read SettingsTable $settings
 */
final class TableChatContext extends TableContext
{
    public const string users = 'users';
    public const string bots = 'bots';
    public const string moderatorPromptPieces = 'moderatorPromptPieces';
    public const string settings = 'settings';

    /**
     * Registers all chat tables with their data sources.
     */
    public function configure(): void
    {
        $this->register(self::users, new UsersTable());
        $this->register(self::bots, new BotsTable());
        $this->register(self::moderatorPromptPieces, new ModeratorPromptPiecesTable());
        $this->register(self::settings, new SettingsTable());
    }
}
