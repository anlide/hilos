<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\Consensus\ConsensusInspection;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeNodeReportDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceNodesPage;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use Hilos\Tests\Unit\Cluster\Peer\NamedPeerTestTransport;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestMarkers;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestTls;
use Hilos\Tests\Unit\Fixtures\MaintenanceNodesTestCase;
use Hilos\Tests\Unit\Fixtures\MaintenanceNodesTestHilos;
use ReflectionClass;

/** Uses the actual page answer, browser classifier, RT-sync bridge and typed peer input. */
final class HilosMaintenanceNodesPageTest extends MaintenanceNodesTestCase
{
    private PageSignalRouter $pages;
    private array $sockets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $agent = $this->createMock(PageAgentInterface::class);
        $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT));
        $this->pages = new PageSignalRouter(new HilosPageFactory($agent, MaintenanceNodesTestHilos::class), new ActionRouteConfig());
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        parent::tearDown();
    }

    public function testReadOnlyAdminPageUsesTheExistingOwnerAndProtocol(): void
    {
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosMaintenanceNodesPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosMaintenanceNodesPage::REACH);
        self::assertSame(HilosAgentType::HILOS_INDEX, AbstractHilosMaintenanceNodesPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame([], AbstractHilosMaintenanceNodesPage::ACTIONS);
        self::assertSame([], AbstractHilosMaintenanceNodesPage::READING_ACTIONS);
        self::assertSame([], AbstractHilosMaintenanceNodesPage::SIGNALS);
    }

    public function testInitialPageResponseCarriesTheSharedHeadAndTwentyFiveRowWindow(): void
    {
        $this->publish([self::node('node-b'), self::node('node-a', 'verifying', true)]);
        $this->drain();
        $this->subscribe();
        $frames = $this->drain();
        self::assertSame(['page_response'], array_column($frames, 'name'));
        $payload = $frames[0]['wire']['payload'];
        self::assertSame('hilos_maintenance', $payload['data']['pageHeadOwner']['page']);
        self::assertSame('Maintenance', $payload['data']['pageHeadOwner']['label']);
        self::assertSame([
            ['page' => 'hilos_maintenance', 'label' => 'Overview'],
            ['page' => 'hilos_maintenance_nodes', 'label' => 'Nodes'],
        ], $payload['data']['pageTabs']);
        $window = $payload['windows']['hilosMaintenanceNodes'];
        self::assertSame(25, $window['limit']);
        self::assertSame(2, $window['totalCount']);
        self::assertSame([['field' => 'nodeId', 'direction' => 'asc']], $window['sort']);
        self::assertSame(['node-a', 'node-b'], array_column($window['rows'], 'rowKey'));
        self::assertSame(['rowKey' => 'node-a', 'slots' => ['node' => self::node('node-a', 'verifying', true)]], $window['rows'][0]);
        self::assertFalse(Hilos::$sr->isTableWindowOwed('nodes', HilosMaintenanceNodesTable::TABLE));
    }

    public function testColdProjectionIsOwedAndFirstRtSyncPaysTheWholeWindow(): void
    {
        $this->subscribe();
        $frames = $this->drain();
        self::assertArrayHasKey('hilosMaintenanceNodes', $frames[0]['wire']['payload']['refusedWindows']);
        self::assertArrayNotHasKey('hilosMaintenanceNodes', $frames[0]['wire']['payload']['windows'] ?? []);
        self::assertTrue(Hilos::$sr->isTableWindowOwed('nodes', HilosMaintenanceNodesTable::TABLE));
        $this->publish([self::node('node-a')]);
        $this->flushSync();
        $frames = $this->drain();
        self::assertSame(['table_window'], array_column($frames, 'name'));
        self::assertSame([['rowKey' => 'node-a', 'slots' => ['node' => self::node('node-a')]]], $frames[0]['wire']['rows']);
        self::assertFalse(Hilos::$sr->isTableWindowOwed('nodes', HilosMaintenanceNodesTable::TABLE));
        $this->publish([self::node('node-a', 'verifying', true)]);
        $this->flushSync();
        $frames = $this->drain();
        self::assertSame(['table_viewport_delta'], array_column($frames, 'name'));
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $frames[0]['wire']['kind']);
        self::assertTrue($frames[0]['wire']['row']['slots']['node']['quiesced']);
    }

    public function testColdProjectionCanRecoverToAnAuthoritativeEmptyWindow(): void
    {
        $this->subscribe();
        $this->drain();
        $this->publish([]);
        $this->flushSync();
        $frames = $this->drain();
        self::assertSame(['table_window'], array_column($frames, 'name'));
        self::assertSame([], $frames[0]['wire']['rows']);
        self::assertSame(0, $frames[0]['wire']['totalCount']);
        self::assertFalse(Hilos::$sr->isTableWindowOwed('nodes', HilosMaintenanceNodesTable::TABLE));
    }

    public function testAViewerReceivesTheColdEmptyWindowCatchUp(): void
    {
        Hilos::$browser->administrator = false;
        Hilos::$browser->viewer = true;
        $this->subscribe();
        $this->drain();

        $this->publish([]);
        $this->flushSync();

        $frames = $this->drain();
        self::assertSame(['table_window'], array_column($frames, 'name'));
        self::assertSame([], $frames[0]['wire']['rows']);
        self::assertSame(0, $frames[0]['wire']['totalCount']);
        self::assertFalse(Hilos::$sr->isTableWindowOwed('nodes', HilosMaintenanceNodesTable::TABLE));
    }

    public function testTheColdEmptyCatchUpDoesNotBypassLostAdminAdmission(): void
    {
        $this->subscribe();
        $this->drain();
        Hilos::$browser->administrator = false;

        $this->publish([]);
        $this->flushSync();

        self::assertSame([], $this->drain());
        self::assertTrue(Hilos::$sr->isTableWindowOwed('nodes', HilosMaintenanceNodesTable::TABLE));
    }

    public function testFullFreezeKeepsTheColdEmptyWindowOwedEvenToTheInitiator(): void
    {
        $this->subscribe();
        $this->drain();
        $this->state->phase = 'active';
        $this->state->initiatorAcceptKey = 'nodes';

        $this->publish([]);
        $this->flushSync();

        self::assertSame([], $this->drain());
        self::assertTrue(Hilos::$sr->isTableWindowOwed('nodes', HilosMaintenanceNodesTable::TABLE));
    }

    public function testBrowserRowsDeclareRuntimeInterestWithoutReadingTheProjection(): void
    {
        $worker = $this->createMock(WorkerManager::class);
        self::assertSame([ProtectedModeRuntime::RT_ITEM],
            new ReflectionClass(WorkerManager::class)->getMethod('pageReadsRt')->invoke($worker, AbstractHilosMaintenanceNodesPage::PAGE));
        self::assertSame([ProtectedModeRuntime::RT_ITEM], Hilos::$browser->rtSourceKeysOfPage(AbstractHilosMaintenanceNodesPage::PAGE));
        self::assertNull($this->state->maintenanceNodes);
    }

    public function testViewerSeesAllSixFieldsInitiallyAndLiveWhileANonViewerIsDenied(): void
    {
        Hilos::$browser->administrator = false;
        $this->publish([self::node('node-a')]);
        $this->drain();
        $this->subscribe();
        self::assertSame(403, $this->drain()[0]['wire']['httpCode']);
        Hilos::$browser->viewer = true;
        $this->subscribe();
        $frames = $this->drain();
        self::assertSame(self::node('node-a'), $frames[0]['wire']['payload']['windows']['hilosMaintenanceNodes']['rows'][0]['slots']['node']);
        $this->publish([self::node('node-a', 'active', false)]);
        $this->flushSync();
        $frames = $this->drain();
        self::assertSame(self::node('node-a', 'active', false), $frames[0]['wire']['row']['slots']['node']);
    }

    public function testFullFreezeDeniesInitialAndLiveEvenToTheInitiator(): void
    {
        foreach (['activating', 'active'] as $phase) {
            $this->state->phase = $phase;
            $this->state->initiatorAcceptKey = 'nodes';
            $this->subscribe();
            self::assertSame(503, $this->drain()[0]['wire']['httpCode']);
        }
        $this->state->phase = 'inactive';
        $this->publish([self::node('node-a')]);
        $this->drain();
        $this->subscribe();
        $this->drain();
        $this->state->phase = 'active';
        $this->publish([self::node('node-a', 'active')]);
        $this->flushSync();
        $frames = $this->drain();
        self::assertSame([], $frames, 'A full freeze releases no live data, even to the initiator');
    }

    public function testVerificationWindowKeepsItsExistingInitiatorAdmissionOnBothDeliveries(): void
    {
        $this->state->phase = 'verifying';
        $this->state->initiatorAcceptKey = 'nodes';
        $this->publish([self::node('node-a', 'verifying')]);
        $this->drain();
        $this->subscribe('outsider');
        self::assertSame(503, $this->drain()[0]['wire']['httpCode']);
        $this->subscribe();
        self::assertSame('page_response', $this->drain()[0]['name']);
        Hilos::$sr->unsubscribeFromPage(AbstractHilosMaintenanceNodesPage::PAGE,
            new WebSocketPageUnsubscribeSignalDTO('outsider', AbstractHilosMaintenanceNodesPage::PAGE));
        $this->publish([self::node('node-a', 'verifying', true)]);
        $this->flushSync();
        $frames = $this->drain();
        self::assertSame(['table_viewport_delta'], array_column($frames, 'name'));
        self::assertSame('nodes', $frames[0]['acceptKey']);
    }

    public function testStandaloneDirectSubscribeIsNotFound(): void
    {
        putenv('CLUSTER_ENABLED=false');
        $this->subscribe();
        $frames = $this->drain();
        self::assertSame(['subscription_page_error'], array_column($frames, 'name'));
        self::assertSame(404, $frames[0]['wire']['httpCode']);
    }

    public function testStandaloneTableViewportIsRefusedWithoutRowData(): void
    {
        $this->publish([self::node('node-a')]);
        $this->drain();
        $this->subscribe();
        $this->drain();
        putenv('CLUSTER_ENABLED=false');
        $this->pages->dispatchTableViewport(new WebSocketTableViewportSignalDTO('nodes', AbstractHilosMaintenanceNodesPage::PAGE,
            HilosMaintenanceNodesTable::TABLE, limit: 25), 'ws', AbstractHilosMaintenanceNodesPage::PAGE);
        $frames = $this->drain();
        self::assertSame(['table_window_refused'], array_column($frames, 'name'));
        self::assertArrayNotHasKey('rows', $frames[0]['wire']);
        self::assertTrue(Hilos::$sr->isTableWindowOwed('nodes', HilosMaintenanceNodesTable::TABLE));
    }

    public function testIncomingPeerReportReachesTheRealInitialAndLiveTableResult(): void
    {
        $leadership = $this->createMockForIntersectionOfInterfaces([Leadership::class, ConsensusInspection::class]);
        $leadership->method('leaderId')->willReturn('node-b');
        $leadership->method('amLeader')->willReturn(false);
        $leadership->method('term')->willReturn(7);
        Hilos::$cluster->registerLeadership($leadership);
        $server = new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-a', NodeRole::Master, []), [],
            PeerTestTls::unread(), PeerTestMarkers::shared());
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$near, $far] = $pair;
        $this->sockets = $pair;
        socket_set_nonblock($near);
        socket_set_nonblock($far);
        $link = new PeerLink($near, $server, NodeIdentity::of('node-a', NodeRole::Master, []), false,
            new NamedPeerTestTransport($near, 'node-b'));
        Closure::bind(static function () use ($server, $link): void { $server->clients[] = $link; }, null, PeerServer::class)();
        foreach ([new PeerHelloDTO(PeerProtocol::VERSION, 'node-b', NodeRole::Master, [], PeerTestMarkers::onWire(), null),
            new PeerReadyDTO(false), new PeerProtectedModeNodeReportDTO('verifying', 7, ['node-a', 'node-b'], ['node-a'], 'node-a')] as $frame) {
            socket_write($far, $frame->toJson() . "\n");
            $link->read();
        }
        $tick = static function (float $now) use ($server): void {
            Closure::bind(static function () use ($server, $now): void { $server->tickProtectedModeNodeReports($now); }, null,
                PeerServer::class)();
        };
        $tick(100.0);
        $this->drain();
        $this->subscribe();
        $frames = $this->drain();
        $rows = $frames[0]['wire']['payload']['windows']['hilosMaintenanceNodes']['rows'];
        self::assertSame(['node-a', 'node-b'], array_column($rows, 'rowKey'));
        self::assertFalse($rows[0]['slots']['node']['quiesced']);
        self::assertTrue($rows[1]['slots']['node']['quiesced']);
        socket_write($far, new PeerProtectedModeNodeReportDTO('verifying', 7, ['node-a', 'node-b'], [], 'node-a')->toJson() . "\n");
        $link->read();
        $tick(101.0);
        $this->flushSync();
        $frames = $this->drain();
        self::assertSame(['table_viewport_delta'], array_column($frames, 'name'));
        self::assertSame('node-a', $frames[0]['wire']['rowKey']);
        self::assertTrue($frames[0]['wire']['row']['slots']['node']['quiesced']);
    }

    private function subscribe(string $acceptKey = 'nodes'): void
    {
        $dto = new WebSocketPageSubscribeSignalDTO($acceptKey, AbstractHilosMaintenanceNodesPage::PAGE);
        $this->pages->dispatchPageSubscribe($dto, 'ws', AbstractHilosMaintenanceNodesPage::PAGE);
        Hilos::$sr->subscribeToPage(AbstractHilosMaintenanceNodesPage::PAGE, $dto);
    }

    /** Drains actual sync DTOs through the worker's production SourceChange conversion, then flushes. */
    private function flushSync(): void
    {
        $worker = $this->createMock(WorkerManager::class);
        $record = new ReflectionClass(WorkerManager::class)->getMethod('recordBrowserSourceChange');
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof RtSyncUpdatedSignalData) {
                $record->invoke($worker, RtSyncUpdatedSignalData::fromArray($signal->data->toArray()));
            }
        }
        Hilos::$browser->flushToSignalRouter();
    }

    private function drain(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'wire' => $signal->data->data->toArray(),
                    'acceptKey' => $signal->data->targetAcceptKey];
            }
        }
        return $frames;
    }
}
