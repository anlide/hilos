<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvMismatchPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonNodeOverviewPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonNodePage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Node-addressed Daemon pages refuse malformed or unknown nodes before page_response. */
final class HilosDaemonNodePageSubscribeTest extends TestCase
{
    private const string ACCEPT_KEY = 'daemon-node-test';

    private ?RtContext $previousRt = null;
    private ?SignalRouter $previousRouter = null;
    private ?ClusterContext $previousCluster = null;
    private ?EnvAccessor $previousEnv = null;
    private ?BrowserContext $previousBrowser = null;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousBrowser = Hilos::$browser;

        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new DaemonNodePageTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$browser = null;
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonNodeOverviewPage::onPictureForgotten();
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonNodeOverviewPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        RtTruthSourceRegistry::unregisterDaemon(HilosClusterNode::RT_COLLECTION);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        Hilos::$browser = $this->previousBrowser;
        putenv('CLUSTER_ENABLED');
        putenv('CLUSTER_NODE_ID');
        putenv('CLUSTER_NODE_ROLE');

        parent::tearDown();
    }

    /** @param class-string<AbstractHilosDaemonNodePage> $pageClass Node page under admission */
    #[DataProvider('nodePages')]
    public function testKnownOfflineNodeStillReceivesThePage(string $pageClass): void
    {
        $this->publishNode('offline-node', false);
        $page = new $pageClass(new DaemonNodePageTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, $this->address('offline-node'));

        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
    }

    public function testEnvironmentPageUsesTheSameNodeGuard(): void
    {
        $this->publishNode('standalone', true);
        $page = new DaemonNodePageTestEnvPage(new DaemonNodePageTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, $this->address('standalone'));

        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
    }

    /** @param class-string<AbstractHilosDaemonNodePage> $pageClass Node page under admission */
    #[DataProvider('nodePages')]
    public function testMissingNodeIdIsAParameterRefusal(string $pageClass): void
    {
        $page = new $pageClass(new DaemonNodePageTestAgent());

        $this->expectException(MissingPageRouteParamException::class);
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
    }

    /** @param class-string<AbstractHilosDaemonNodePage> $pageClass Node page under admission */
    #[DataProvider('nodePages')]
    public function testEmptyNodeIdIsAParameterRefusal(string $pageClass): void
    {
        $page = new $pageClass(new DaemonNodePageTestAgent());

        $this->expectException(MissingPageRouteParamException::class);
        $page->onSubscribe(self::ACCEPT_KEY, $this->address(''));
    }

    /** @param class-string<AbstractHilosDaemonNodePage> $pageClass Node page under admission */
    #[DataProvider('nodePages')]
    public function testMalformedNodeIdIsAParameterRefusal(string $pageClass): void
    {
        $page = new $pageClass(new DaemonNodePageTestAgent());

        $this->expectException(MissingPageRouteParamException::class);
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([
            HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 42,
        ]));
    }

    /** @param class-string<AbstractHilosDaemonNodePage> $pageClass Node page under admission */
    #[DataProvider('nodePages')]
    public function testUnknownNodeIsAResourceRefusal(string $pageClass): void
    {
        $page = new $pageClass(new DaemonNodePageTestAgent());

        $this->expectException(PageResourceNotFoundException::class);
        $this->expectExceptionMessage('Unknown Daemon node: missing-node');
        $page->onSubscribe(self::ACCEPT_KEY, $this->address('missing-node'));
    }

    /** @param class-string<AbstractHilosDaemonNodePage> $pageClass Node page under admission */
    #[DataProvider('nodePages')]
    public function testUpdateRechecksTheNodeBeforeASecondResponse(string $pageClass): void
    {
        $this->publishNode('known-node', true);
        $page = new $pageClass(new DaemonNodePageTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, $this->address('known-node'));
        Hilos::$sr?->getNextQueuedSignal();

        $this->expectException(PageResourceNotFoundException::class);
        $page->onUpdateSubscription(self::ACCEPT_KEY, $this->address('missing-node'));
    }

    /** @param class-string<AbstractHilosDaemonNodePage> $pageClass Node page under admission */
    #[DataProvider('nodePages')]
    public function testUpdateOfAKnownNodeRefreshesThePageResponse(string $pageClass): void
    {
        $this->publishNode('known-node', true);
        $page = new $pageClass(new DaemonNodePageTestAgent());

        $page->onUpdateSubscription(self::ACCEPT_KEY, $this->address('known-node'));

        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
    }

    public function testEnvironmentMismatchAnswersStandalone(): void
    {
        $page = new DaemonNodePageTestEnvMismatchPage(new DaemonNodePageTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
    }

    public function testEnvironmentMismatchAnswersInClusterMode(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=cluster-node');
        putenv('CLUSTER_NODE_ROLE=master');
        $page = new DaemonNodePageTestEnvMismatchPage(new DaemonNodePageTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
    }

    /** @return array<string, array{class-string<AbstractHilosDaemonNodePage>}> Shared node admission and the new leaf */
    public static function nodePages(): array
    {
        return [
            'workers' => [DaemonNodePageTestWorkersPage::class],
            'node overview' => [DaemonNodePageTestOverviewPage::class],
        ];
    }

    /**
     * @param string $nodeId Node to address, possibly empty for a refusal
     * @return PageRouteParams Route parameters
     */
    private function address(string $nodeId): PageRouteParams
    {
        return new PageRouteParams([HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $nodeId]);
    }

    /**
     * @param string $nodeId Node to publish
     * @param bool $online Whether it is reachable now
     */
    private function publishNode(string $nodeId, bool $online): void
    {
        Hilos::$rt?->hilosClusterNodes->actions->publish($nodeId, 'master', [], null, $online, microtime(true));
    }
}

/** Runtime context with only the framework node roster. */
final class DaemonNodePageTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/** Test agent supplying the page signal source. */
final class DaemonNodePageTestAgent implements PageAgentInterface
{
    /** @return string Agent id */
    public function getId(): string
    {
        return 'daemon-node-test-agent';
    }

    /** @return SignalSourceInterface Agent signal source */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}

final class DaemonNodePageTestWorkersPage extends AbstractHilosDaemonWorkersPage
{
}

final class DaemonNodePageTestOverviewPage extends AbstractHilosDaemonNodeOverviewPage
{
}

final class DaemonNodePageTestEnvPage extends AbstractHilosDaemonEnvPage
{
}

final class DaemonNodePageTestEnvMismatchPage extends AbstractHilosDaemonEnvMismatchPage
{
}
