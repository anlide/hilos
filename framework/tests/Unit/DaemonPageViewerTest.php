<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use PHPUnit\Framework\TestCase;

/** Only answered Daemon pages count viewers; closes and roster loss release them. */
final class DaemonPageViewerTest extends TestCase
{
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = null;
        Hilos::$rt = null;
        ClusterDaemonPictureMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        parent::tearDown();
    }

    public function testAnAnsweredOverviewAddsOneViewerAndUnsubscribeRemovesIt(): void
    {
        $page = new DaemonViewerOverviewPage(new DaemonViewerPageAgent());
        $page->onSubscribe('ak-1', new PageRouteParams([]));
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
        self::assertSame(['ak-1'], ClusterDaemonPictureMirror::viewerKeys());
        $page->onSubscribe('ak-1', new PageRouteParams([]));
        self::assertSame(1, ClusterDaemonPictureMirror::viewerCount());
        $page->onUnsubscribe('ak-1');
        self::assertSame(0, ClusterDaemonPictureMirror::viewerCount());
    }

    public function testARefusedNodeAddressDoesNotCountAsAViewer(): void
    {
        $page = new DaemonViewerWorkersPage(new DaemonViewerPageAgent());
        try {
            $page->onSubscribe('ak-refused', new PageRouteParams([]));
            self::fail('The missing node address must be refused');
        } catch (MissingPageRouteParamException) {
            self::assertSame(0, ClusterDaemonPictureMirror::viewerCount());
            self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        }
    }

    public function testRemoteViewerStaysUntilExplicitCloseAndLocalRosterLossRemovesIt(): void
    {
        Hilos::$rt = new DaemonViewerRtContext();
        Hilos::$rt->configure();
        $agent = new DaemonViewerProbeAgent();

        ClusterDaemonPictureMirror::addViewer('remote');
        $agent->tickAt(microtime(true));
        self::assertSame(['remote'], ClusterDaemonPictureMirror::viewerKeys());
        $agent->onSignalConnectionClose(new WebSocketCloseSignalDTO('remote'), 'websocket', 'connection_close');
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());

        Hilos::$rt->connectionsSource()?->add(DaemonViewerConnection::create('local', null));
        ClusterDaemonPictureMirror::addViewer('local');
        $agent->tickAt(microtime(true) + 1.0);
        Hilos::$rt->connectionsSource()?->remove('local');
        $agent->tickAt(microtime(true) + 2.0);
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }
}

final class DaemonViewerOverviewPage extends AbstractHilosDaemonPage
{
}

final class DaemonViewerWorkersPage extends AbstractHilosDaemonWorkersPage
{
}

final class DaemonViewerPageAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'daemon-viewer-test';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}

final class DaemonViewerProbeAgent extends AbstractHilosDaemonAgent
{
    public function tickAt(float $now): void
    {
        $this->watchIfDue($now);
    }
}

final class DaemonViewerConnection extends HilosConnection
{
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** @extends HilosConnections<DaemonViewerConnection> */
final class DaemonViewerConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonViewerConnection::class;
}

final class DaemonViewerRtContext extends RtContext
{
    public const string connections = 'daemonViewerConnections';

    public function configure(): void
    {
        $this->_stateCollections[self::connections] = DaemonViewerConnections::init();
    }
}
