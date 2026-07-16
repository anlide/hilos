<?php

declare(strict_types=1);

namespace Demo\Cluster\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/**
 * Cluster demo environment catalog.
 *
 * Inherits every framework key from the stub — including the whole CLUSTER_* set —
 * and only overrides the DB_DATABASE default (the stub default is an empty string).
 * The per-node schema name is supplied by compose env; this default is the fallback.
 */
final class ClusterEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the cluster demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'hilos-demo-cluster',
                EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => true,
                EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
            ],
        ]);
    }
}
