<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Testing\RollbackIntegrationTestCase;

/** Mounts the shop before the managed rollback scope and writes categories as their library does. */
abstract class CategoriesLibraryEnvironment extends RollbackIntegrationTestCase
{
    private ?CategoriesLibraryAgent $library = null;
    private ?int $executionToken = null;

    /** Uses the migrated test database without taking over an already mounted project context. */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: Hilos::$db === null);
        $this->library = new CategoriesLibraryAgent();
        OwnershipDeclaration::claimAll($this->library);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->library->getId()));
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
