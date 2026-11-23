<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\EcommerceShop\CLI\EcommerceShopCliManager;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\CLI\CliApplication;

/**
 * CLI - Entry point for the ecommerce-shop demo CLI.
 *
 * Provides command-line management interface for the ecommerce-shop demo daemon. Supports
 * commands: daemon:status, daemon:monitor, db:*, help. The invariant startup spine lives
 * in CliApplication.
 */

CliApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    cliManagerClass: EcommerceShopCliManager::class,
    argv: $argv,
    databaseInit: static fn () => Database::initialize(),
);
