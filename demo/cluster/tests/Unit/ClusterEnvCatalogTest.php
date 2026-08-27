<?php

declare(strict_types=1);

namespace Demo\Cluster\Tests\Unit;

use Demo\Cluster\Environment\ClusterEnvCatalog;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvCatalogConstants;
use PHPUnit\Framework\TestCase;

/**
 * Guards what this demo's env catalog claims of an operator setting a node up.
 *
 * The catalog is read before a node starts: every entry it marks required is a value the
 * daemon refuses to start without. So the two WebSocket names matter here in a way they
 * did not while values were read on the way past - this demo is headless, nothing gives
 * them a value, and a catalog that inherited them would refuse every node.
 */
final class ClusterEnvCatalogTest extends TestCase
{
    public function testWebSocketNamesAreNotAskedOfAHeadlessNode(): void
    {
        $catalog = ClusterEnvCatalog::getCatalog();

        $this->assertArrayNotHasKey(EnvConstants::WEBSOCKET_HOST->name, $catalog);
        $this->assertArrayNotHasKey(EnvConstants::WEBSOCKET_PORT->name, $catalog);
    }

    public function testDatabaseNameFallsBackToThisDemoSchema(): void
    {
        $entry = ClusterEnvCatalog::getCatalog()[EnvConstants::DB_DATABASE->name] ?? null;

        $this->assertIsArray($entry);
        $this->assertSame('hilos-demo-cluster', $entry[EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] ?? null);
    }

    public function testEveryOtherRequiredNameSurvivesTheStub(): void
    {
        // Dropping the two WebSocket entries must not cost the node anything else it is
        // held to: these eight are what a cluster node is still refused a start without.
        $catalog = ClusterEnvCatalog::getCatalog();

        $required = [];
        foreach ($catalog as $key => $entry) {
            if (($entry[EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING] ?? false) === true) {
                $required[] = $key;
            }
        }

        $this->assertSame([
            EnvConstants::HILOS_DAEMON_HOST->name,
            EnvConstants::HTTP_STATUS_HOST->name,
            EnvConstants::HTTP_STATUS_PORT->name,
            EnvConstants::WORKER_COMM_HOST->name,
            EnvConstants::WORKER_COMM_PORT->name,
            EnvConstants::DAEMON_LOG_FILE->name,
            EnvConstants::DAEMON_ERROR_LOG_FILE->name,
            EnvConstants::APP_ENV->name,
        ], $required);
    }
}
