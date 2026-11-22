<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\StockLibraryAgent;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Testing\RollbackIntegrationTestCase;

/**
 * Mounts the shop and claims the stock for its library before the managed rollback scope, then starts the library
 * inside the scope the way its worker does.
 *
 * It also writes the rows the stock stands on — warehouses and products with their categories and
 * pictures — straight into their tables past their libraries, with no file on disk ({@see CatalogFixtureRows}).
 */
abstract class StockLibraryEnvironment extends RollbackIntegrationTestCase
{
    use CatalogFixtureRows;
    use EmptyCatalog;

    private ?StockLibraryAgent $library = null;
    private ?int $executionToken = null;

    /**
     * Uses the migrated test database without taking over an already mounted project context.
     *
     * The products of a case go in past the ORM, and the database carries the seeded catalog, so
     * the loaded memory is forgotten first, before the scope, where forgetting is allowed: a
     * products library started earlier in this process declared the products whole, and a
     * product written after that would be read as absent. Forgotten, they are read again inside
     * the case, and the rollback forgets that read with the rows.
     * Then the library claims what it owns and pushes its own frame.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: Hilos::$db === null);
        Hilos::$db->forgetLoadedDataForTest();
        $this->library = new StockLibraryAgent();
        OwnershipDeclaration::claimAll($this->library);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->library->getId()));
    }

    /**
     * Starts the case from an empty catalog, then runs the library's start under its frame, so the
     * stock is declared complete before the case writes a row; the rollback brings the seed back
     * and forgets that read with the rows.
     */
    protected function setUpInTransaction(): void
    {
        self::emptyCatalog();
        self::assertNotNull($this->library);
        $this->library->onStart();
    }

    /** Releases the library's claim and restores the caller after the harness rolls back. */
    protected function releaseIntegrationEnvironment(): void
    {
        if ($this->library !== null) {
            TruthSourceRegistry::unregisterAgent($this->library->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
            $this->library = null;
        }
        if ($this->executionToken !== null) {
            ExecutionContext::pop($this->executionToken);
            $this->executionToken = null;
        }
    }
}
