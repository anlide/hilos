<?php

declare(strict_types=1);

namespace Demo\Chat;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Settings\ChatSettingsAccessor;
use Demo\Chat\Environment\ChatEnvAccessor;
use Demo\Chat\Frontend\ChatFrontendProjection;
use Demo\Chat\Fs\FsChatContext;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Frontend\FrontendProjectionContext;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Hilos - Main app facade for data access.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]
 * - Hilos::$db->users
 * - Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL]->string()
 * - Hilos::$rt->connections
 * - Hilos::$rt->userStates
 * - Hilos::$table->users
 * - Hilos::$fs->quarantine, Hilos::$fs->published, Hilos::$fs->tmp
 *
 * @property-read DbChatContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor
 * @property-read SettingsAccessor $setting Settings accessor
 * @property-read RtChatContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read TableChatContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read FsChatContext $fs Filesystem context (narrows parent's FsContext for IDE)
 */
final class Hilos extends \Hilos\Hilos
{
    /**
     * Creates the project environment accessor with the chat env catalog.
     *
     * @return EnvAccessor Environment accessor
     */
    protected static function createEnv(): EnvAccessor
    {
        return new ChatEnvAccessor();
    }

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
     * Creates the project settings accessor with the chat settings catalog.
     *
     * @return SettingsAccessor Settings accessor
     */
    protected static function createSetting(): SettingsAccessor
    {
        return new ChatSettingsAccessor();
    }

    /**
     * Creates and returns the runtime context instance.
     *
     * @return ?RtChatContext Runtime context or null if runtime is not available
     */
    protected static function createRuntime(): ?RtContext
    {
        return new RtChatContext();
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

    /**
     * Creates and returns the filesystem context.
     *
     * @return ?FsChatContext The filesystem context instance.
     */
    protected static function createFs(): ?FsContext
    {
        return new FsChatContext();
    }

    /**
     * Creates the worker-local frontend projection accumulator.
     *
     * @return ?ChatFrontendProjection Chat frontend projection context
     */
    protected static function createFrontendProjection(): ?FrontendProjectionContext
    {
        return new ChatFrontendProjection();
    }
}
