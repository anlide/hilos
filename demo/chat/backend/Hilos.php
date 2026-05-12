<?php

declare(strict_types=1);

namespace Demo\Chat;

use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Settings\ChatSettingsAccessor;
use Demo\Chat\Environment\ChatEnvAccessor;
use Demo\Chat\Fs\ChatFsContext;
use Demo\Chat\Projection\ChatProjectionContext;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Core\Browser\Context\BrowserContext;
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
 * - Hilos::$browser
 * - Hilos::$fs->quarantine, Hilos::$fs->published, Hilos::$fs->tmp
 * - Hilos::$projection->subscribeSnapshot(PageConstants::MAIN, $acceptKey, $params)
 *
 * @property-read ChatDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read ChatEnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read ChatSettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read ChatRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read ChatTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read ChatBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 * @property-read ChatFsContext $fs Filesystem context (narrows parent's FsContext for IDE)
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
     * @return ChatDbContext Chat database context
     */
    protected static function createDb(): DbContext
    {
        return new ChatDbContext();
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
     * @return ?ChatRtContext Chat runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new ChatRtContext();
    }

    /**
     * Creates the chat table context.
     *
     * @return ?ChatTableContext Chat table context
     */
    protected static function createTable(): ?TableContext
    {
        return new ChatTableContext();
    }

    /**
     * Creates the chat browser-facing context.
     *
     * @return ?ChatBrowserContext Chat browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new ChatBrowserContext();
    }

    /**
     * Creates the chat filesystem context.
     *
     * @return ?ChatFsContext Chat filesystem context
     */
    protected static function createFs(): ?FsContext
    {
        return new ChatFsContext();
    }

    /**
     * Creates the worker-local projection context for the chat demo.
     *
     * @return ?ChatProjectionContext Chat projection context
     */
    protected static function createProjection(): ?ProjectionContext
    {
        return new ChatProjectionContext();
    }
}
