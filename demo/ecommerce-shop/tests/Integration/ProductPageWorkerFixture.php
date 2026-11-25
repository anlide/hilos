<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart as EntitySessionProductCart;
use Demo\EcommerceShop\Database\View\Collection\SessionProductCarts;
use Demo\EcommerceShop\Database\View\Collection\UserProductCarts;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\ProductPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageMovedSignalData;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/** Runs a storefront subscription and its browser facts through the production worker. */
trait ProductPageWorkerFixture
{
    protected const string GUEST = 'product-page-guest';
    protected const string VISITOR = 'product-page-visitor';
    protected const string ADMIN = 'product-page-admin';
    protected const string OTHER_GUEST = 'product-page-other-guest';
    protected const string THIRD_GUEST = 'product-page-third-guest';
    private const string CONNECTIONS_OWNER = 'product-page-test';

    protected AgentManager $agents;
    protected EcommerceShopDbContext $reader;
    private EcommerceShopWorkerManager $worker;
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private bool $claimedConnections = false;

    /** Mounts a production worker and a separate ORM reader without opening a daemon link. */
    protected function mountProductPageWorker(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->reader = new EcommerceShopDbContext();
        $this->reader->configure();
        Hilos::initBrowser();
        $this->worker = new EcommerceShopWorkerManager(1);
        $this->agents = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($this->worker);
        Hilos::$browser->bindPageResender($this->worker);
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::CONNECTIONS_OWNER);
        $this->claimedConnections = true;
    }

    /** Releases connections and restores the facades used outside the case. */
    protected function releaseProductPageWorker(): void
    {
        try {
            if (isset($this->agents)) {
                foreach ($this->agents->getAgents() as $agent) {
                    if ($agent instanceof ProductAgent) {
                        RtTruthSourceRegistry::unregisterAgent($agent->getId());
                        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
                    }
                }
            }
            if ($this->claimedConnections) {
                ExecutionContext::run(new ExecutionFrame(), static function (): void {
                    foreach ([self::GUEST, self::VISITOR, self::ADMIN, self::OTHER_GUEST, self::THIRD_GUEST] as $acceptKey) {
                        Hilos::$rt->connections[$acceptKey]?->actions->unregister();
                        SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
                    }
                });
                RtTruthSourceRegistry::unregisterAgent(self::CONNECTIONS_OWNER);
            }
        } finally {
            Hilos::$sr = $this->previousRouter;
            if ($this->previousBrowser === null) {
                Hilos::resetBrowser();
            } else {
                Hilos::initBrowser($this->previousBrowser);
            }
        }
    }

    /** @param WorkerDTO ...$messages Addressed subscriptions or applied facts handled before one flush */
    protected function deliver(WorkerDTO ...$messages): void
    {
        $this->flushReaderAfter(function () use ($messages): void {
            foreach ($messages as $message) {
                $this->worker->handleDaemonMessage($message);
            }
        });
    }

    /**
     * @param Closure $step Incoming worker work before the browser flush
     * @param int $containedFailures Failures contained by the browser publisher
     */
    protected function flushReaderAfter(Closure $step, int $containedFailures = 0): void
    {
        $writer = Hilos::$db;
        Hilos::$db = $this->reader;
        try {
            ExecutionContext::run(new ExecutionFrame(), static function () use ($step, $containedFailures): void {
                $step();
                self::assertCount($containedFailures, Hilos::$browser->flushToSignalRouter());
            });
        } finally {
            Hilos::$db = $writer;
        }
    }

    /**
     * @param string $acceptKey Connection opening or replacing a page
     * @param ?string $slug Product address, or null when the param is missing
     */
    protected function subscribe(string $acceptKey, ?string $slug): void
    {
        $agent = $slug === null || $slug === '' ? new EcommerceShopAgent() : new ProductAgent($slug);
        $agentId = $agent->getId();
        if (!$this->agents->hasAgent($agentId)) {
            if ($agent instanceof ProductAgent) {
                OwnershipDeclaration::claimRtRows($agent);
            }
            $this->agents->addAgent($agentId, $agent);
        }
        $params = $slug === null ? [] : [ProductPageSubscribeParams::SLUG => $slug];
        $this->deliver(new DaemonAgentMessageDTO($agentId, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(ProductPage::PAGE),
            new WebSocketPageSubscribeSignalDTO($acceptKey, ProductPage::PAGE, $params),
        )));
    }

    /**
     * @param int $productId Product whose loaded view should retain the last applied row
     * @return Product Reader's loaded product
     */
    protected function warmProduct(int $productId): Product
    {
        $previous = Hilos::$db;
        Hilos::$db = $this->reader;
        try {
            return Hilos::$db->products[$productId];
        } finally {
            Hilos::$db = $previous;
        }
    }

    /** @param int $productId Product added to the guest's stored cart */
    protected static function addGuestCartLine(int $productId): WorkerDbSyncCreatedMessageDTO
    {
        self::asFixtureOwner(new GuestCartsLibraryAgent(), static fn () => Hilos::$db->sessionProductCarts->actions->add(101, $productId));
        $row = Database::sql('SELECT * FROM session_product_cart WHERE session_id = ? AND product_id = ?', [101, $productId])->rows()[0];

        return new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
            EcommerceShopDbContext::sessionProductCarts,
            (string) $row[EntitySessionProductCart::id],
            $row,
        ));
    }

    /**
     * @param string $oldSlug Requested or held product address
     * @param string $newSlug Current product address
     */
    protected function assertMove(string $oldSlug, string $newSlug): void
    {
        $move = $this->takeFrame(self::GUEST)->data;
        self::assertInstanceOf(PageMovedSignalData::class, $move);
        self::assertSame(ProductPage::PAGE, $move->page);
        self::assertSame([ProductPageSubscribeParams::SLUG => $oldSlug], $move->params);
        self::assertSame(ProductPage::PAGE, $move->targetPage);
        self::assertSame([ProductPageSubscribeParams::SLUG => $newSlug], $move->targetParams);
    }

    /**
     * @param string $acceptKey Connection awaiting the only page frame
     * @return WebSocketSignalData Page frame addressed to that connection
     */
    protected function takeFrame(string $acceptKey): WebSocketSignalData
    {
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame($acceptKey, $frames[0]->targetAcceptKey);

        return $frames[0];
    }

    /**
     * @param bool $includeFocus Whether to collect table deltas as well as page frames
     * @return list<WebSocketSignalData> Page responses, refusals and moves for any local connection
     */
    protected function takeFrames(bool $includeFocus = false): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (
                $signal->data instanceof WebSocketSignalData
                && (($signal->data->data instanceof PageResponseSignalData
                        && array_keys($signal->data->data->payload->data) !== [ProductPage::DATA_PRODUCT_VIEWERS])
                    || $signal->data->data instanceof PageSubscriptionErrorSignalData
                    || ($includeFocus && $signal->data->data instanceof TableViewportDeltaDTO)
                    || $signal->data->data instanceof PageMovedSignalData)
            ) {
                $frames[] = $signal->data;
            }
        }

        return $frames;
    }

    /** @param callable(): void $write Fixture operation through its owner */
    protected static function asFixtureOwner(AbstractAgent $owner, callable $write): void
    {
        try {
            OwnershipDeclaration::claimAll($owner);
            ExecutionContext::run(new ExecutionFrame(agentId: $owner->getId()), $write);
        } finally {
            TruthSourceRegistry::unregisterAgent($owner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($owner->getId()));
        }
    }

    /**
     * @param Closure(): void $step Worker work with instrumented cart reads
     * @param ?int $failedProduct Product whose guest count should fail
     * @param ?Closure(string, int): void $beforeCount Step immediately before a collection counts its rows
     * @return array<string, list<int>> Read product ids in call order, by cart collection
     */
    protected function observeCartCountReads(Closure $step, ?int $failedProduct = null, ?Closure $beforeCount = null): array
    {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $original = $views->getValue($this->reader);
        $cartViews = [];
        $reads = [];
        foreach ([EcommerceShopDbContext::sessionProductCarts, EcommerceShopDbContext::userProductCarts] as $collection) {
            $reads[$collection] = [];
            $observe = static function (int $productId) use (&$reads, $collection, $failedProduct, $beforeCount): void {
                $reads[$collection][] = $productId;
                if ($collection === EcommerceShopDbContext::sessionProductCarts && $productId === $failedProduct) {
                    throw new DatabaseException('Cart count read failed');
                }
                $beforeCount?->__invoke($collection, $productId);
            };
            $cartViews[$collection] = new class ($original[$collection], $observe) extends DbCollection {
                public function __construct(
                    private readonly SessionProductCarts|UserProductCarts $original,
                    private readonly Closure $observe,
                ) {
                }

                public function countForProduct(int $productId): int
                {
                    ($this->observe)($productId);

                    return $this->original->countForProduct($productId);
                }
            };
        }
        $views->setValue($this->reader, array_replace($original, $cartViews));
        try {
            $step();
        } finally {
            $views->setValue($this->reader, $original);
        }

        return $reads;
    }
}
