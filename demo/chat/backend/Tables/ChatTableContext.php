<?php

declare(strict_types=1);

namespace Demo\Chat\Tables;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\AdminUsersTable;
use Demo\Chat\Tables\Bot\BotsTable;
use Demo\Chat\Tables\HilosUser\HilosUsersTable;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * ChatTableContext - App-specific table context ($table layer).
 *
 * Registers admin users, Hilos users, bots, moderator prompt pieces, and settings tables.
 * Accessed via Hilos::$table->adminUsers, Hilos::$table->hilosUsers, Hilos::$table->bots, etc.
 *
 * @property-read AdminUsersTable $adminUsers
 * @property-read HilosUsersTable $hilosUsers
 * @property-read BotsTable $bots
 * @property-read ModeratorPromptPiecesTable $moderatorPromptPieces
 * @property-read HilosSettingsTable $settings
 */
final class ChatTableContext extends TableContext
{
    public const string adminUsers = 'adminUsers';
    public const string hilosUsers = 'hilosUsers';
    public const string bots = 'bots';
    public const string moderatorPromptPieces = 'moderatorPromptPieces';
    public const string settings = HilosSettingsTable::TABLE;

    /**
     * Registers chat table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}
