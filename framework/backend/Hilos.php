<?php

declare(strict_types=1);

namespace Hilos;

use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Frontend\FrontendProjectionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Main framework facade providing global access to core layer singletons.
 *
 * Application classes should extend this class and expose:
 * - Hilos::$db    — database layer
 * - Hilos::$setting — catalog-backed setting values
 * - Hilos::$rt    — runtime layer
 * - Hilos::$table — table layer
 * - Hilos::$fs    — filesystem layer
 * - Hilos::$sr    — signal router
 * - Hilos::$frontend — worker-local frontend projection accumulator
 */
abstract class Hilos
{
    /** @var ?DbContext Database layer singleton */
    public static ?DbContext $db = null;

    /** @var ?SettingsAccessor Catalog-backed settings accessor */
    public static ?SettingsAccessor $setting = null;

    /** @var ?RtContext Runtime layer singleton */
    public static ?RtContext $rt = null;

    /** @var ?TableContext Table layer singleton */
    public static ?TableContext $table = null;

    /** @var ?FsContext Filesystem layer singleton */
    public static ?FsContext $fs = null;

    /** @var ?SignalRouter Signal router singleton */
    public static ?SignalRouter $sr = null;

    /** @var ?FrontendProjectionContext Frontend projection accumulator */
    public static ?FrontendProjectionContext $frontend = null;

    /** @var ?AnalyticsCollector Analytics collector singleton */
    public static ?AnalyticsCollector $ac = null;

    /**
     * Initialize all layers (setting, db, rt, table).
     *
     * @throws HilosException When a layer factory or configure step cannot initialize its singleton
     */
    public static function init(): void
    {
        if (static::$setting === null) {
            static::$setting = static::createSetting();
        }

        if (static::$db === null) {
            static::$db = static::createDb();
            static::$db->configure();
        }

        if (static::$rt === null) {
            static::$rt = static::createRuntime();
            static::$rt?->configure();
        }

        if (static::$table === null) {
            static::$table = static::createTable();
            static::$table?->configure();
        }

        if (static::$fs === null) {
            static::$fs = static::createFs();
            static::$fs?->configure();
        }

        if (static::$frontend === null) {
            static::$frontend = static::createFrontendProjection();
        }
    }

    /**
     * Initialize signal router layer.
     *
     * Called separately from init() because the signal router is created
     * by DaemonManager/WorkerManager during process startup, which may
     * happen at a different lifecycle stage than the other layers.
     *
     * @param SignalRouter $signalRouter Signal router instance
     */
    public static function initSignalRouter(SignalRouter $signalRouter): void
    {
        static::$sr = $signalRouter;
    }

    /**
     * Initialize analytics collector.
     *
     * @param ?AnalyticsCollector $analyticsCollector Analytics collector instance
     */
    public static function initAnalytics(?AnalyticsCollector $analyticsCollector = null): void
    {
        static::$ac = $analyticsCollector ?? new AnalyticsCollector();
    }

    /**
     * Create database context instance.
     *
     * @return DbContext Database context instance
     */
    abstract protected static function createDb(): DbContext;

    /**
     * Create settings accessor.
     *
     * @return SettingsAccessor Settings accessor
     */
    protected static function createSetting(): SettingsAccessor
    {
        return new SettingsAccessor();
    }

    /**
     * Create runtime instance.
     *
     * @return ?RtContext Runtime context or null if not used
     */
    protected static function createRuntime(): ?RtContext
    {
        return null;
    }

    /**
     * Create table context instance.
     *
     * @return ?TableContext Table context or null if not used
     */
    protected static function createTable(): ?TableContext
    {
        return null;
    }

    /**
     * Create filesystem context instance.
     *
     * @return ?FsContext Filesystem context or null if not used
     */
    protected static function createFs(): ?FsContext
    {
        return null;
    }

    /**
     * Create frontend projection accumulator.
     *
     * The default framework has no projection. Projects may return a worker-local
     * projection context that consumes DB/RT sync facts and emits frontend updates.
     *
     * @return ?FrontendProjectionContext Frontend projection context or null if not used
     */
    protected static function createFrontendProjection(): ?FrontendProjectionContext
    {
        return null;
    }
}
