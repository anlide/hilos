<?php

namespace Hilos\Hilos\Database;

use Hilos\Database\Hilos\Hilos as BaseHilos;

/**
 * Hilos database namespace - base for application Hilos facade.
 *
 * Application extends this to expose Hilos::$db, Hilos::$rt, Hilos::$table.
 */
abstract class Hilos extends BaseHilos
{
}
