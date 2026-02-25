<?php

namespace Hilos;

use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\DbContext;
use Hilos\Runtime\View\Context\RtContext;

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

    /** @var ?TableContext Table layer singleton */
    public static ?TableContext $table = null;

    /**
     * Initialize all layers.
     */
    public static function init(): void
    {
        if (static::$db === null) {
            static::$db = static::createDb();
            static::$db->configure();
        }

        if (static::$rt === null) {
            static::$rt = static::createRuntime();
        }

        if (static::$table === null) {
            static::$table = static::createTable();
            static::$table?->configure();
        }
    }

    /**
     * Create database context instance.
     *
     * @return DbContext
     */
    abstract protected static function createDb(): DbContext;

    /**
     * Create runtime instance.
     *
     * @return ?TRuntime
     */
    protected static function createRuntime(): ?RtContext
    {
        return null;
    }

    /**
     * Create table context instance.
     *
     * @return ?TableContext
     */
    protected static function createTable(): ?TableContext
    {
        return null;
    }
}
