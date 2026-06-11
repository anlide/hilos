<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/**
 * Simple-todo demo environment catalog.
 *
 * The framework stub default for DB_DATABASE is an empty string, so the demo
 * overrides it with its own database name; everything else inherits the stub.
 */
final class TodoEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the simple-todo demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'hilos-demo-simple-todo',
                EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => true,
                EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
            ],
        ]);
    }
}
