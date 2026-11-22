<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Files\HilosFiles;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Mounts the shop's browser and its own signal router over the products environment, turns the admin
 * view mode on, and claims the connections register the page gate reads a subscriber's identity from.
 *
 * The runtime writes run outside any agent, as the daemon's do: the environment beneath runs under the
 * stock library's frame, which owns neither the connections nor the view mode.
 */
abstract class AdminProductsPageEnvironment extends ProductsTableEnvironment
{
    private const string TEST_AGENT = 'admin-products-page-test';

    /** @var HilosFiles&object{published: list<array{string, string, list<string>, string}>} Files door recording publication requests */
    protected HilosFiles $recorder;

    private ?SignalRouter $previousRouter = null;
    private bool $claimed = false;
    private ?HilosFiles $door = null;

    /** Mounts the browser, router, connections claim, view mode and recording files door. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        parent::prepareIntegrationEnvironment();
        Hilos::initBrowser();
        Hilos::$sr = new EcommerceShopSignalRouter();
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->claimed = true;
        self::unattended(static function (): void {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        });
        $this->door = Hilos::$files;
        self::assertNotNull($this->door);
        $this->recorder = new class ($this->door->storage) extends HilosFiles {
            /** @var list<array{string, string, list<string>, string}> Connection, target, uploads and reply signal for each request */
            public array $published = [];

            /**
             * @param string $acceptKey Connection the uploads belong to
             * @param string $target Upload target
             * @param list<string> $clientUploadIds Requested uploads
             * @param string $replySignal Publication answer name
             */
            public function publishUploads(string $acceptKey, string $target, array $clientUploadIds, string $replySignal): void
            {
                $this->published[] = [$acceptKey, $target, $clientUploadIds, $replySignal];
            }
        };
        Hilos::$files = $this->recorder;
    }

    /** Restores the files door, releases runtime claims and restores the router and browser. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if ($this->door !== null) {
                Hilos::$files = $this->door;
                $this->door = null;
            }
            if ($this->claimed) {
                $this->claimed = false;
                self::unattended(static function (): void {
                    Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
                    Hilos::$rt->connections->actions->clear();
                });
                RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
                RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
            }
            Hilos::$sr = $this->previousRouter;
            Hilos::initBrowser();
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }

    /**
     * Runs a runtime write outside any agent, the way the daemon writes.
     *
     * @param callable(): void $write The write
     */
    protected static function unattended(callable $write): void
    {
        ExecutionContext::run(new ExecutionFrame(), $write);
    }
}
