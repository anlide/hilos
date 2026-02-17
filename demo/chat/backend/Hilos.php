<?php

declare(strict_types=1);

namespace Demo\Chat;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Hilos\Runtime\Context\ChatRuntime;
use Hilos\Core\Table\DataSource\EntityTableDataSource;
use Hilos\Database\Hilos\DbContext;
use Hilos\Hilos\Runtime\Context\RtContext;
use Hilos\Hilos\Table\TableHub;

/**
 * Main app facade for data access.
 *
 * Usage:
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 * - Hilos::$table?->users
 */
final class Hilos extends \Hilos\Hilos
{
    public const string connections = 'connections';
    public const string user = 'user';

    /** @see DbChatContext Collection names for $db (re-exported for TruthSourceRegistry, DTOs, etc.) */
    public const string users = DbChatContext::users;
    public const string events = DbChatContext::events;
    public const string bots = DbChatContext::bots;
    public const string moderators = DbChatContext::moderators;

    protected static function createDb(): DbContext
    {
        return new DbChatContext();
    }

    protected static function createRuntime(): ?RtContext
    {
        return ChatRuntime::init();
    }

    protected static function createTable(): ?TableHub
    {
        $table = new TableHub();
        $table->register(DbChatContext::users, new EntityTableDataSource(static::$db->users));
        return $table;
    }
}
