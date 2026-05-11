<?php

declare(strict_types=1);

namespace Demo\Chat;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Settings\ChatSettingsAccessor;
use Demo\Chat\Environment\ChatEnvAccessor;
use Demo\Chat\Fs\FsChatContext;
use Demo\Chat\Projection\ChatProjectionContext;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Projection\ProjectionContext;
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
 * - Hilos::$projection->subscribeSnapshot(PageConstants::MAIN, $acceptKey, $params)
 *
 * @property-read DbChatContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read ChatEnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read ChatSettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read RtChatContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read TableChatContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read FsChatContext $fs Filesystem context (narrows parent's FsContext for IDE)
 * @property-read ChatProjectionContext $projection Projection context (narrows parent's ProjectionContext for IDE)
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
     * Creates the chat database context.
     *
     * @return DbChatContext Chat database context
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
     * Creates the chat runtime context.
     *
     * @return RtChatContext Chat runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new RtChatContext();
    }

    /**
     * Creates the chat table context.
     *
     * @return TableChatContext Chat table context
     */
    protected static function createTable(): ?TableContext
    {
        return new TableChatContext();
    }

    /**
     * Creates the chat filesystem context.
     *
     * @return FsChatContext Chat filesystem context
     */
    protected static function createFs(): ?FsContext
    {
        return new FsChatContext();
    }

    /**
     * Creates the worker-local projection context for the chat demo.
     *
     * @return ChatProjectionContext Chat projection context
     */
    protected static function createProjection(): ?ProjectionContext
    {
        return new ChatProjectionContext();
    }
}
