<?php

declare(strict_types=1);

namespace Demo\Chat\Tables;

use Demo\Chat\Tables\AdminUser\AdminUsersTable;
use Demo\Chat\Tables\Bot\BotsTable;
use Demo\Chat\Tables\HilosUser\HilosUsersTable;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Demo\Chat\Tables\Settings\SettingsTable;
use Hilos\Core\Table\Context\TableContext;

/**
 * TableChatContext - App-specific table context ($table layer).
 *
 * Registers admin users, Hilos users, bots, moderator prompt pieces, and settings tables.
 * Accessed via Hilos::$table->adminUsers, Hilos::$table->hilosUsers, Hilos::$table->bots, etc.
 *
 * @property-read AdminUsersTable $adminUsers
 * @property-read HilosUsersTable $hilosUsers
 * @property-read BotsTable $bots
 * @property-read ModeratorPromptPiecesTable $moderatorPromptPieces
 * @property-read SettingsTable $settings
 */
final class TableChatContext extends TableContext
{
    public const string adminUsers = 'adminUsers';
    public const string hilosUsers = 'hilosUsers';
    public const string bots = 'bots';
    public const string moderatorPromptPieces = 'moderatorPromptPieces';
    public const string settings = 'settings';

    /**
     * Registers all chat table definitions.
     */
    public function configure(): void
    {
        $this->register(self::adminUsers, new AdminUsersTable());
        $this->register(self::hilosUsers, new HilosUsersTable());
        $this->register(self::bots, new BotsTable());
        $this->register(self::moderatorPromptPieces, new ModeratorPromptPiecesTable());
        $this->register(self::settings, new SettingsTable());
    }
}
