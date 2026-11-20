<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;

/**
 * Starts the stock library as its worker does, forgets what that start loaded, then starts the guest carts library
 * the way its worker does.
 *
 * The guest carts library reads the stock as any process but its holder does: by SQL, one product at a time, and a
 * row it already holds as the last write in this process left it. The stock library keeps its claim, so a case that
 * needs the stock to change after a cart has read it changes it through the stock library, as the process would see
 * it; a case reads the carts and the stock back past the ORM.
 */
abstract class GuestCartsLibraryEnvironment extends StockLibraryEnvironment
{
    private ?GuestCartsLibraryAgent $carts = null;
    private ?int $executionToken = null;

    /**
     * The carts library claims what it owns and runs its start under its own frame, so the carts are declared
     * complete before the first case writes a line; the frame stays on top of the stock library's for the case.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetLoadedDataForTest();
        $this->carts = new GuestCartsLibraryAgent();
        OwnershipDeclaration::claimAll($this->carts);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->carts->getId()));
        $this->carts->onStart();
    }

    /** Releases the carts library's claim and frame, then the stock library's, after the harness rolls back. */
    protected function releaseIntegrationEnvironment(): void
    {
        if ($this->carts !== null) {
            TruthSourceRegistry::unregisterAgent($this->carts->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->carts->getId()));
        }
        if ($this->executionToken !== null) {
            ExecutionContext::pop($this->executionToken);
            $this->executionToken = null;
        }
        $this->carts = null;
        parent::releaseIntegrationEnvironment();
    }

    /**
     * @return GuestCartsLibraryAgent The guest carts library the environment started
     */
    protected function carts(): GuestCartsLibraryAgent
    {
        return $this->carts ?? self::fail('The guest carts library is not started');
    }
}
