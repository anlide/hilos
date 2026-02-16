<?php

namespace Hilos\Database\Hilos;

use Hilos\Hilos\Runtime\Context\RtContext;
use Hilos\Hilos\Table\TableHub;

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
abstract class Hilos extends DbContext
{
    /** @var ?static Database layer singleton */
    public static ?self $db = null;

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
            static::$db = new static();
        }

        if (static::$rt === null) {
            static::$rt = static::createRuntime();
        }

        static::configureCollections();

        if (static::$table === null) {
            static::$table = static::createTable();
        }
    }

    /**
     * Configure DB collections.
     */
    protected static function configureCollections(): void
    {
    }

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
