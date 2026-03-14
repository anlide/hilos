<?php

declare(strict_types=1);

namespace Demo\Chat;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\DbContext;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Main app facade for data access.
 *
 * Usage:
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 * - Hilos::$rt->moderationStates
 * - Hilos::$table->users
 *
 * @property-read DbChatContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read RtChatContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read TableChatContext $table Table context (narrows parent's TableContext for IDE)
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
     * Creates and returns the runtime context instance.
     *
     * @return ?RtChatContext Runtime context or null if runtime is not available
     * @throws StateCollectionNotFoundException If required state collection is not found
     */
    protected static function createRuntime(): ?RtContext
    {
        return RtChatContext::init();
    }

    /**
     * Creates and returns the table context.
     *
     * @return ?TableChatContext The table context instance.
     */
    protected static function createTable(): ?TableContext
    {
        return new TableChatContext();
    }
}
