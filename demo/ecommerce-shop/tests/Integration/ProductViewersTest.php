<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\ProductPage;
use Demo\EcommerceShop\Runtime\State\Item\ProductViewerCount;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Ordinary\RtOrdinaryQueue;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionMethod;
use ReflectionProperty;

/** Owns the viewer test's worker and browser environment around the managed rollback scope. */
abstract class ProductViewersEnvironment extends StockLibraryEnvironment
{
    protected const string FIRST = 'viewer-first';
    protected const string SECOND = 'viewer-second';
    protected const string THIRD = 'viewer-third';
    protected const string FOURTH = 'viewer-fourth';
    protected const string CONNECTIONS_OWNER = 'product-viewers-test';

    protected EcommerceShopWorkerManager $worker;
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;

    /** @var array<string, ProductAgent> Started agents by address */
    protected array $agents = [];

    /** Mounts the viewer test's worker and page-delivery environment over the rollback scope. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        parent::prepareIntegrationEnvironment();
        Hilos::initBrowser();
        $this->worker = new EcommerceShopWorkerManager(1);
        Hilos::$browser->bindPageResender($this->worker);
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::CONNECTIONS_OWNER);
        $consumer = SourceConsumer::agent(self::CONNECTIONS_OWNER);
        SourceInterestRegistry::register(SourceChange::KIND_RT, EcommerceShopRtContext::connections, $consumer);
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, EcommerceShopRtContext::connections);
        foreach (ProductAgent::READS_DB as $collection) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $collection, $consumer);
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $collection);
        }
    }

    /** Releases the extra subscriptions and restores facades after the test rollback. */
    protected function releaseIntegrationEnvironment(): void
    {
        foreach ([...array_map(static fn (ProductAgent $agent): string => $agent->getId(), $this->agents), self::CONNECTIONS_OWNER] as $agentId) {
            RtTruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        foreach ([self::FIRST, self::SECOND, self::THIRD, self::FOURTH] as $acceptKey) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
        }
        RtOrdinaryAdmission::reset();
        Hilos::$sr = $this->previousRouter;
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        } else {
            Hilos::initBrowser($this->previousBrowser);
        }
        parent::releaseIntegrationEnvironment();
    }
}

/** Product subscriptions count identities and send only each connection's visible result. */
#[IntegrationTest]
final class ProductViewersTest extends ProductViewersEnvironment
{
    private Product $product;
    private Product $neighbor;

    public function testGuestsReceiveTheFullCardThenOnlyChangedViewerData(): void
    {
        $this->connect(self::FIRST, 1);
        $this->connect(self::SECOND, 2);
        $this->subscribe(self::FIRST, $this->product->slug);
        $this->assertReplies([self::FIRST => [0, true]]);

        $this->subscribe(self::SECOND, $this->product->slug);
        $this->assertReplies([self::SECOND => [1, true], self::FIRST => [1, false]]);
        self::assertSame(2, $this->aggregate());

        $this->leave(self::SECOND);
        $this->assertReplies([self::FIRST => [0, false]]);
        self::assertSame(1, $this->aggregate());
    }

    public function testLateIdentityAndUserChangesGroupTabsWithoutDoubleCounting(): void
    {
        $this->connect(self::FIRST, 1);
        $firstUser = $this->person();
        $secondUser = $this->person();
        $this->connect(self::THIRD, 3, $firstUser);
        $this->connect(self::FOURTH, 4, $firstUser);

        $this->subscribe(self::FIRST, $this->product->slug);
        $this->assertReplies([self::FIRST => [0, true]]);
        $this->connect(self::SECOND, 2);
        $this->subscribe(self::SECOND, $this->product->slug);
        $this->assertReplies([self::SECOND => [1, true], self::FIRST => [1, false]]);
        self::assertSame(2, $this->aggregate());

        $this->subscribe(self::THIRD, $this->product->slug);
        $this->assertReplies([self::THIRD => [2, true], self::FIRST => [2, false], self::SECOND => [2, false]]);
        $this->subscribe(self::FOURTH, $this->product->slug);
        $this->assertReplies([self::FOURTH => [2, true]]);
        self::assertSame(3, $this->aggregate());

        $this->asConnectionOwner(static fn () => Hilos::$rt->connections[self::SECOND]->actions->bindUser($firstUser));
        $this->deliverUserBinding(self::SECOND, $firstUser, null);
        $this->assertReplies([
            self::FIRST => [1, false],
            self::SECOND => [1, false],
            self::THIRD => [1, false],
            self::FOURTH => [1, false],
        ]);
        self::assertSame(2, $this->aggregate());

        $this->asConnectionOwner(static fn () => Hilos::$rt->connections[self::FIRST]->actions->bindUser($secondUser));
        $this->deliverUserBinding(self::FIRST, $secondUser, null);
        $this->assertReplies([]);
        self::assertSame(2, $this->aggregate());

        $this->asConnectionOwner(static fn () => Hilos::$rt->connections[self::FIRST]->actions->bindUser($firstUser));
        $this->deliverUserBinding(self::FIRST, $firstUser, $secondUser);
        $this->assertReplies([
            self::FIRST => [0, false],
            self::SECOND => [0, false],
            self::THIRD => [0, false],
            self::FOURTH => [0, false],
        ]);
        self::assertSame(1, $this->aggregate());

        // The total stays at one. Only the departed identity's own tab sees one other viewer.
        $this->asConnectionOwner(static fn () => Hilos::$rt->connections->actions->repointSessionToken(self::SECOND, 'waiting', null));
        $this->asConnectionOwner(static fn () => Hilos::$rt->connections[self::SECOND]->actions->bindUser(null));
        $this->deliverUserBinding(self::SECOND, null, $firstUser);
        $this->assertReplies([self::SECOND => [1, false]]);
        self::assertSame(1, $this->aggregate());
    }

    public function testConnectionSnapshotReplacementKeepsOnlyTheTabsStillServed(): void
    {
        $slug = $this->product->slug;
        $this->connect(self::FIRST, 1);
        $this->connect(self::SECOND, 2);
        $this->connect(self::THIRD, 3);
        $this->subscribe(self::FIRST, $slug);
        $this->assertReplies([self::FIRST => [0, true]]);
        $this->subscribe(self::SECOND, $slug);
        $this->assertReplies([self::SECOND => [1, true], self::FIRST => [1, false]]);

        $resource = Hilos::$sr->pageResource(self::SECOND);
        $row = Hilos::$rt->connections[self::SECOND]->toArray();
        $deleted = new RtSyncDeletedSignalData(EcommerceShopRtContext::connections, self::SECOND, $row);
        $created = new RtSyncCreatedSignalData(EcommerceShopRtContext::connections, self::SECOND, $row);
        $apply = new ReflectionMethod(WorkerManager::class, 'handleRtSyncMessage');
        RtWorkDriver::runSynchronously($apply->invoke($this->worker, $deleted));
        self::assertNull(Hilos::$rt->connections[self::SECOND]);
        self::assertSame($resource, Hilos::$sr->pageResource(self::SECOND));
        self::assertSame(1, $this->aggregate());
        $this->assertReplies([self::FIRST => [0, false]]);

        RtWorkDriver::runSynchronously($apply->invoke($this->worker, $created));
        self::assertSame(2, $this->aggregate());
        $this->assertReplies([self::FIRST => [1, false]]);

        $this->subscribe(self::THIRD, $slug);
        $this->assertReplies([self::THIRD => [2, true], self::FIRST => [2, false], self::SECOND => [2, false]]);
        $this->leave(self::THIRD);
        $this->assertReplies([self::FIRST => [1, false], self::SECOND => [1, false]]);

        RtWorkDriver::runSynchronously($apply->invoke($this->worker, $deleted));
        $this->assertReplies([self::FIRST => [0, false]]);
        $this->leave(self::SECOND);
        RtWorkDriver::runSynchronously($apply->invoke($this->worker, $created));
        self::assertNull(Hilos::$sr->pageResource(self::SECOND));
        self::assertSame(1, $this->aggregate());
        $this->assertReplies([]);
    }

    public function testFirstAnswerUsesIdentityAppliedBeforeLocalDispatch(): void
    {
        $slug = $this->product->slug;
        $user = $this->person();
        $this->connect(self::FIRST, 1);
        $this->connect(self::SECOND, 2, $user);
        $this->connect(self::THIRD, 3);
        $this->subscribe(self::FIRST, $slug);
        $this->assertReplies([self::FIRST => [0, true]]);
        $this->subscribe(self::SECOND, $slug);
        $this->assertReplies([self::SECOND => [1, true], self::FIRST => [1, false]]);
        self::assertSame(2, $this->aggregate());

        $this->asConnectionOwner(static fn () => Hilos::$rt->connections[self::FIRST]->actions->bindUser($user));
        $this->subscribe(self::THIRD, $slug);

        $frames = $this->takeFrames();
        self::assertSame(self::THIRD, $frames[0]->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]->data);
        self::assertArrayHasKey(ProductPage::DATA_PRODUCT, $frames[0]->data->payload->data);
        self::assertSame(1, $frames[0]->data->payload->data[ProductPage::DATA_PRODUCT_VIEWERS]['otherViewerCount']);
        self::assertCount(1, $frames, 'The queued identity update has already been reflected in the audience');
        self::assertSame(2, $this->aggregate());
    }

    public function testRepeatReplacementRefusalAndCloseReleaseTheOriginalAudience(): void
    {
        $this->connect(self::FIRST, 1);
        $this->connect(self::SECOND, 2);
        $slug = $this->product->slug;
        $this->subscribe(self::FIRST, $slug);
        $this->assertReplies([self::FIRST => [0, true]]);
        $this->subscribe(self::SECOND, $slug);
        $this->assertReplies([self::SECOND => [1, true], self::FIRST => [1, false]]);

        Hilos::$sr->markPageDeliveryFailure(self::FIRST);
        Hilos::$browser->resendPageWhole(ProductPage::PAGE, self::FIRST);
        Hilos::$browser->flushToSignalRouter();
        $this->assertReplies([self::FIRST => [1, true]]);
        $this->subscribe(self::FIRST, $slug);
        $this->assertReplies([self::FIRST => [1, true]]);
        self::assertSame(2, $this->aggregate());

        // The master delivers the old owner's unsubscribe before the new owner's subscribe.
        $this->leave(self::SECOND);
        $this->subscribe(self::SECOND, $this->neighbor->slug);
        $this->assertReplies([self::FIRST => [0, false], self::SECOND => [0, true, $this->neighbor->slug]]);

        $this->worker->refuseDeletedResource(ProductPage::PAGE, self::SECOND);
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame(self::SECOND, $frames[0]->targetAcceptKey);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $frames[0]->data);
        self::assertSame(HttpConstants::HTTP_NOT_FOUND, $frames[0]->data->httpCode);
        self::assertNull(Hilos::$rt->productViewerCounts[$this->neighbor->slug]);

        $this->asConnectionOwner(static fn () => Hilos::$rt->connections[self::FIRST]->actions->unregister());
        $this->dispatch($slug, SignalTypeConstants::CONNECTION_CLOSE, new WebSocketCloseSignalDTO(self::FIRST));
        $this->leave(self::FIRST);
        $this->assertReplies([]);
        self::assertNull($this->aggregate());
        self::assertNull(Hilos::$sr->pageSubscription(self::FIRST));

        $this->connect(self::FIRST, 1);
        $this->assertReplies([]);
        self::assertNull($this->aggregate());
    }

    public function testRuntimeRowsHavePerAddressOwnershipAndCarryTheDeletedProductId(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        try {
            $owner = $this->start($this->product->slug);
            $neighbor = $this->start($this->neighbor->slug);
            self::assertSame(
                [EcommerceShopRtContext::connections, EcommerceShopRtContext::productViewerCounts],
                SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::agent($owner->getId()), SourceChange::KIND_RT),
            );

            $this->connect(self::FIRST, 1);
            $this->connect(self::SECOND, 2);
            $this->connect(self::THIRD, 3);
            $this->subscribe(self::FIRST, $this->product->slug);
            $this->assertReplies([self::FIRST => [0, true]]);
            $this->subscribe(self::SECOND, $this->product->slug);
            $this->assertReplies([self::SECOND => [1, true], self::FIRST => [1, false]]);
            $this->subscribe(self::THIRD, $this->neighbor->slug);
            $this->assertReplies([self::THIRD => [0, true, $this->neighbor->slug]]);

            $collection = EcommerceShopRtContext::productViewerCounts;
            self::assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, $collection));
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, $collection);

            $address = $this->product->slug;
            $deletedRow = Hilos::$rt->productViewerCounts[$address]->toArray();
            $deleted = SourceChange::rtDeleted(
                EcommerceShopRtContext::productViewerCounts,
                $address,
                $deletedRow,
            );
            self::assertSame(
                $deletedRow,
                RtRowValues::nativeMap($deleted->carriedRow()),
            );
            self::assertSame([], RtRowValues::nativeMap($deleted->carriedPrevious()));
            foreach ([
                static fn () => Hilos::$rt->productViewerCounts[$address]->actions->setViewerCount(9),
                static fn () => Hilos::$rt->productViewerCounts[$address]->actions->remove(),
                fn () => Hilos::$rt->productViewerCounts->actions->create('unowned', $this->product->id, 9),
            ] as $write) {
                try {
                    ExecutionContext::run(new ExecutionFrame(agentId: $neighbor->getId()), $write);
                    self::fail('An agent wrote another address');
                } catch (RtTruthSourceWriteNotAllowedException) {
                    self::assertSame(2, $this->aggregate());
                }
            }

            $this->worker->handleDaemonMessage(new AgentStopDTO($owner->getId()));
            $this->assertReplies([]);
            self::assertNull($this->aggregate());
            self::assertSame(1, Hilos::$rt->productViewerCounts[$this->neighbor->slug]->viewerCount);
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
        }
    }

    public function testRestartRestoresAnsweredTabsFromTheSameWorker(): void
    {
        $slug = $this->product->slug;
        $this->connect(self::FIRST, 1);
        $this->connect(self::SECOND, 1);
        $this->connect(self::THIRD, 2);
        $this->connect(self::FOURTH, 3);
        $this->subscribe(self::FIRST, $slug);
        $this->assertReplies([self::FIRST => [0, true]]);
        $this->subscribe(self::SECOND, $slug);
        $this->assertReplies([self::SECOND => [0, true]]);
        $this->subscribe(self::FOURTH, $slug);
        $this->assertReplies([self::FOURTH => [1, true], self::FIRST => [1, false], self::SECOND => [1, false]]);
        $this->leave(self::FOURTH);
        $this->assertReplies([self::FIRST => [0, false], self::SECOND => [0, false]]);

        $owner = $this->start($slug);
        $this->worker->handleDaemonMessage(new AgentStopDTO($owner->getId()));
        unset($this->agents[$slug]);
        self::assertNull($this->aggregate());
        $this->assertReplies([]);
        SourceInterestRegistry::readsWhatIsDelivered();
        try {
            $replacement = $this->start($slug);
            self::assertNotSame($owner, $replacement);
            self::assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, EcommerceShopRtContext::productViewerCounts));
            self::assertSame(1, ExecutionContext::run(new ExecutionFrame(agentId: $replacement->getId()), $this->aggregate(...)));
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
        }
        $this->assertReplies([self::FIRST => [0, false], self::SECOND => [0, false]]);

        $this->subscribe(self::THIRD, $slug);
        $this->assertReplies([self::THIRD => [1, true], self::FIRST => [1, false], self::SECOND => [1, false]]);
        self::assertSame(2, $this->aggregate());
        $this->leave(self::FIRST);
        $this->assertReplies([]);
        self::assertSame(2, $this->aggregate());
        $this->leave(self::SECOND);
        $this->assertReplies([self::THIRD => [0, false]]);
        self::assertSame(1, $this->aggregate());
    }

    public function testFullOrdinaryQueueRetriesOnlyTheLatestCountIncludingRemoval(): void
    {
        $this->connect(self::FIRST, 1);
        $this->connect(self::SECOND, 2);
        $this->connect(self::THIRD, 3);
        $agent = $this->start($this->product->slug);
        $queue = new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1);
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static fn () => RtOrdinaryAdmission::bindSourceQueue($queue));

        $room = false;
        RtOrdinaryAdmission::consumeBy(static function () use (&$room): bool {
            return $room;
        });
        try {
            $slug = $this->product->slug;
            $this->subscribe(self::FIRST, $slug);
            $this->assertReplies([self::FIRST => [0, true]]);
            $this->subscribe(self::SECOND, $slug);
            $this->assertReplies([self::SECOND => [1, true], self::FIRST => [1, false]]);
            $this->subscribe(self::THIRD, $slug);
            $this->assertReplies([self::THIRD => [2, true], self::FIRST => [2, false], self::SECOND => [2, false]]);
            self::assertSame(1, $this->aggregate());
            self::assertTrue($agent->hasWorkInFlight());

            $room = true;
            $this->tick($agent);
            $this->assertReplies([]);
            self::assertSame(3, $this->aggregate());
            self::assertFalse($agent->hasWorkInFlight());

            $room = false;
            $this->leave(self::THIRD);
            $this->leave(self::SECOND);
            $this->leave(self::FIRST);
            self::assertSame(2, $this->aggregate());
            self::assertTrue($agent->hasWorkInFlight());

            $room = true;
            $this->tick($agent);
            self::assertFalse($agent->hasWorkInFlight());
            self::assertNull($this->aggregate());
        } finally {
            RtOrdinaryAdmission::consumeBy(null);
        }
    }

    /** Creates two visible products with distinct storefront addresses. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $warehouse = self::warehouse();
        $productId = self::product(ProductKind::STOCK);
        self::stock($productId, $warehouse, 4);
        $neighborId = self::product(ProductKind::STOCK);
        self::stock($neighborId, $warehouse, 5);
        $this->product = Hilos::$db->products[$productId];
        $this->neighbor = Hilos::$db->products[$neighborId];
    }

    /**
     * @param string $slug Agent to start through the worker's real ownership path
     * @return ProductAgent Started agent
     */
    private function start(string $slug): ProductAgent
    {
        if (!isset($this->agents[$slug])) {
            $id = AgentType::PRODUCT . ':' . $slug;
            $this->worker->handleDaemonMessage(new AgentStartDTO($id));
            $manager = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($this->worker);
            $agent = $manager->getAgent($id);
            self::assertInstanceOf(ProductAgent::class, $agent);
            $this->agents[$slug] = $agent;
        }

        return $this->agents[$slug];
    }

    /**
     * @param string $acceptKey Tab to register with the local RT owner
     * @param int $sessionId Session the handshake assigned
     * @param ?int $userId Signed-in person, when signed in
     */
    private function connect(string $acceptKey, int $sessionId, ?int $userId = null): void
    {
        $this->asConnectionOwner(static fn () => Hilos::$rt->connections->actions->register($acceptKey, $userId, sessionId: $sessionId));
    }

    /** @return int Person used by the connection fixtures */
    private function person(): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Product viewer']);

        return Database::lastInsertId();
    }

    /** @param callable(): mixed $write Connection action run with its real fixture claim */
    private function asConnectionOwner(callable $write): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: self::CONNECTIONS_OWNER), $write);
    }

    /**
     * Delivers a committed connection update to local agents while the rollback harness holds reactions.
     *
     * @param string $acceptKey Changed connection
     * @param ?int $userId Current user id
     * @param ?int $previousUserId Previous user id
     */
    private function deliverUserBinding(string $acceptKey, ?int $userId, ?int $previousUserId): void
    {
        foreach ($this->agents as $agent) {
            ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static function () use (
                $acceptKey,
                $agent,
                $previousUserId,
                $userId,
            ): void {
                $agent->onSignalRtSyncUpdated(
                    new RtSyncUpdatedSignalData(
                        EcommerceShopRtContext::connections,
                        $acceptKey,
                        [StateHilosConnection::userId => $userId],
                        previous: [StateHilosConnection::userId => $previousUserId],
                    ),
                    SignalSource::WEBSOCKET,
                    ProductPage::PAGE,
                );
            });
        }
    }

    /**
     * @param string $acceptKey Connection opening the page
     * @param string $slug Product address
     */
    private function subscribe(string $acceptKey, string $slug): void
    {
        $this->dispatch($slug, SignalTypeConstants::PAGE_SUBSCRIBE, new WebSocketPageSubscribeSignalDTO(
            $acceptKey,
            ProductPage::PAGE,
            [ProductPageSubscribeParams::SLUG => $slug],
        ));
    }

    /** @param string $acceptKey Connection leaving the main product address */
    private function leave(string $acceptKey): void
    {
        $this->dispatch($this->product->slug, SignalTypeConstants::PAGE_UNSUBSCRIBE, new WebSocketPageUnsubscribeSignalDTO($acceptKey));
    }

    /**
     * @param string $slug Address of the serving agent
     * @param string $type Browser signal type
     * @param SignalDataInterface $data Addressed signal payload
     */
    private function dispatch(string $slug, string $type, SignalDataInterface $data): void
    {
        $agent = $this->start($slug);
        $this->worker->handleDaemonMessage(new DaemonAgentMessageDTO($agent->getId(), new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType($type),
            new SignalName(ProductPage::PAGE),
            $data,
        )));
    }

    /** @param ProductAgent $agent Agent with an aggregate waiting for admission */
    private function tick(ProductAgent $agent): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), $agent->onTick(...));
    }

    /** @return ?int Current committed aggregate for the main product */
    private function aggregate(): ?int
    {
        return Hilos::$rt->productViewerCounts[$this->product->slug]?->viewerCount;
    }

    /**
     * @param array<string, array{int, bool, 2?: string}> $expected Count, whether a full card, and an optional address
     */
    private function assertReplies(array $expected): void
    {
        $frames = $this->takeFrames();
        self::assertCount(count($expected), $frames);
        foreach ($frames as $frame) {
            $acceptKey = $frame->targetAcceptKey;
            self::assertArrayHasKey($acceptKey, $expected);
            [$count, $full] = $expected[$acceptKey];
            $slug = $expected[$acceptKey][2] ?? $this->product->slug;
            unset($expected[$acceptKey]);
            self::assertInstanceOf(PageResponseSignalData::class, $frame->data);
            self::assertSame(ProductPage::PAGE, $frame->data->pageKey);
            $payload = $frame->data->payload;
            $viewers = ['slug' => $slug, 'otherViewerCount' => $count];
            self::assertSame($viewers, $payload->data[ProductPage::DATA_PRODUCT_VIEWERS]);
            if ($full) {
                self::assertSame($slug, $payload->data[ProductPage::DATA_PRODUCT]['slug']);
            } else {
                self::assertSame([ProductPage::DATA_PRODUCT_VIEWERS => $viewers], $payload->data);
                self::assertSame([], $payload->entities);
                self::assertSame([], $payload->tables);
            }
        }
    }

    /**
     * Drains the local queue and delivers connection facts to the agents as the worker does. The
     * connection actions already applied the fact, so the addressed hook must read current RT.
     *
     * @return list<WebSocketSignalData> Browser page answers and refusals
     */
    private function takeFrames(): array
    {
        $frames = [];
        $dispatchSync = new ReflectionMethod(WorkerManager::class, 'dispatchSyncToLocalAgents');
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($data instanceof RtSyncSignalDataInterface && $data->collectionKey === EcommerceShopRtContext::connections) {
                $dispatchSync->invoke($this->worker, $data);
            } elseif ($data instanceof WebSocketSignalData
                && ($data->data instanceof PageResponseSignalData || $data->data instanceof PageSubscriptionErrorSignalData)
            ) {
                $frames[] = $data;
            }
        }

        return $frames;
    }
}
