<?php

declare(strict_types=1);

namespace Demo\Chat;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Core\Table\DataSource\EntityTableDataSource;
use Hilos\Database\Hilos\DbContext;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Table\TableHub;

/**
 * Main app facade for data access.
 *
 * Usage:
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 * - Hilos::$table?->users
 *
 * @property-read DbChatContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read RtChatContext $rt Runtime context (narrows parent's RtContext for IDE)
 */
final class Hilos extends \Hilos\Hilos
{
    /**
     * Creates and returns a database context instance.
     *
     * @return DbChatContext The database context instance.
     */
    protected static function createDb(): DbContext
    {
        return new DbChatContext();
    }

    /**
     * @return ?RtChatContext The runtime context instance, or null if runtime is not available.
     * @throws StateCollectionNotFoundException if a required state collection is not found in the runtime.
     */
    protected static function createRuntime(): ?RtContext
    {
        return RtChatContext::init();
    }

    /**
     * Creates and registers a new table instance within a TableHub.
     *
     * @return ?TableHub The initialized TableHub instance, or null on failure.
     */
    protected static function createTable(): ?TableHub
    {
        $table = new TableHub();
        $table->register(DbChatContext::users, new EntityTableDataSource(static::$db->users));
        $table->register(DbChatContext::bots, new EntityTableDataSource(static::$db->bots));
        $table->register(DbChatContext::moderatorPromptPieces, new EntityTableDataSource(static::$db->moderatorPromptPieces));
        return $table;
    }
}
