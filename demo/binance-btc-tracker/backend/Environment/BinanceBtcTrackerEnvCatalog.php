<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/**
 * Binance BTC tracker demo environment catalog.
 *
 * The framework stub default for DB_DATABASE is an empty string, so the demo
 * overrides it with its own database name. Everything else - mail, anti-abuse,
 * WebAuthn - inherits the stub: the demo signs people in by password and mails
 * its codes, and declares no provider, no code channel and no backup.
 */
final class BinanceBtcTrackerEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the binance-btc-tracker demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => self::stringEntry(
                'hilos-demo-binance-btc-tracker',
                emptyIsMissing: true,
            ),
        ]);
    }

    /**
     * @param string $default Default value
     * @param bool $emptyIsMissing Whether empty values fall back to defaults
     * @return array<string, mixed> Catalog entry for a string-typed variable
     */
    private static function stringEntry(string $default, bool $emptyIsMissing = false): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
    }
}
