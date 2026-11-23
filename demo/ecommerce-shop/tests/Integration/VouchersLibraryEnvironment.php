<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Testing\RollbackIntegrationTestCase;

/** Mounts the shop before the rollback scope and writes vouchers under their library's claim. */
abstract class VouchersLibraryEnvironment extends RollbackIntegrationTestCase
{
    private ?VouchersLibraryAgent $library = null;
    private ?int $executionToken = null;

    /** Starts with a cold ORM before opening the rollback scope. */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: Hilos::$db === null);
        Hilos::$db->forgetLoadedDataForTest();
        $this->library = new VouchersLibraryAgent();
        OwnershipDeclaration::claimAll($this->library);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->library->getId()));
    }

    /** Releases the library claim and restores the caller after rollback. */
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

    /** @return VouchersLibraryAgent Library with the voucher write claim */
    protected function vouchersLibrary(): VouchersLibraryAgent
    {
        return $this->library ?? self::fail('The vouchers library is not mounted');
    }
}
