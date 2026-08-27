<?php

declare(strict_types=1);

namespace Demo\Cluster\Environment;

use Demo\Cluster\Core\Daemon\ClusterDaemonManager;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/**
 * Cluster demo environment catalog.
 *
 * Takes the framework stub — including the whole CLUSTER_* set — with two edits. It drops
 * WEBSOCKET_HOST and WEBSOCKET_PORT, because a node of this demo has no WebSocket at all
 * ({@see ClusterDaemonManager::createServers()}) and nothing anywhere gives them a value:
 * not the .env.example, not the per-node compose anchor. While a required value was only
 * read when the code reached it, inheriting them cost nothing because the code never
 * reached them; with the daemon checking the environment before it starts, a catalog that
 * still demands them refuses every node. So the catalog has to say "this demo has no
 * WebSocket" itself, rather than lean on nobody asking. And it overrides the DB_DATABASE
 * default, which is an empty string in the stub — the per-node schema name comes from
 * compose env, and this default is the fallback.
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
        $catalog = EnvCatalogStub::getCatalog();
        unset($catalog[EnvConstants::WEBSOCKET_HOST->name], $catalog[EnvConstants::WEBSOCKET_PORT->name]);

        return array_replace($catalog, [
            EnvConstants::DB_DATABASE->name => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'hilos-demo-cluster',
                EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => true,
                EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
            ],
        ]);
    }
}
