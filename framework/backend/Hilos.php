<?php

namespace Hilos;

use Hilos\Database\Context\DbContext;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Table\TableHub;

/**
 * Main framework facade for data access.
 *
 * Application classes should extend this class and expose:
 * - Hilos::$db
 * - Hilos::$rt
 * - Hilos::$table
 *
 * @template TRuntime of RtContext
 */
abstract class Hilos
{
    /** @var ?DbContext Database layer singleton */
    public static ?DbContext $db = null;

    /** @var ?TRuntime Runtime layer singleton */
    public static ?RtContext $rt = null;

    /** @var ?TableHub Table layer singleton */
    public static ?TableHub $table = null;

    /**
     * Initialize all layers.
     */
    public static function init(): void
    {
        if (static::$db === null) {
            static::$db = static::createDb();
        }

        if (static::$rt === null) {
            static::$rt = static::createRuntime();
        }

        static::$db->configure();

        if (static::$table === null) {
            static::$table = static::createTable();
        }
    }

    /**
     * Create database context instance.
     */
    abstract protected static function createDb(): DbContext;

    /**
     * Create runtime instance.
     */
    protected static function createRuntime(): ?RtContext
    {
        return null;
    }

    /**
     * Create table instance.
     */
    protected static function createTable(): ?TableHub
    {
        return null;
    }
}
