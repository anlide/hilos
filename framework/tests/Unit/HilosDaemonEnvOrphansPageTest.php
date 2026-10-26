<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\NodeEnvironmentKey;
use Hilos\DaemonSection\NodeEnvironmentReading;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvResolution;
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvOrphansPage;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The orphans page answers with the node heading and silence, and never the environment catalog. */
final class HilosDaemonEnvOrphansPageTest extends TestCase
{
    private ?BrowserContext $previousBrowser = null;

    private ?ClusterContext $previousCluster = null;
    private ?RtContext $previousRt = null;
    private ?EnvAccessor $previousEnv = null;
    private string|false $previousClusterEnabled = false;

    protected function setUp(): void
    {
        $this->previousBrowser = Hilos::$browser;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRt = Hilos::$rt;
        $this->previousEnv = Hilos::$env;
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED');
        Hilos::$browser = new DaemonEnvOrphansPageTestBrowser(false);
        Hilos::$cluster = null;
        Hilos::$env = new EnvAccessor();
        Hilos::$rt = new DaemonEnvOrphansPageTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Hilos::$browser = $this->previousBrowser;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRt;
        Hilos::$env = $this->previousEnv;
        RtTruthSourceRegistry::unregisterDaemon(HilosClusterNode::RT_COLLECTION);
        putenv($this->previousClusterEnabled === false ? 'CLUSTER_ENABLED' : 'CLUSTER_ENABLED=' . $this->previousClusterEnabled);
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            ClusterDaemonPictureMirror::removeViewer($acceptKey);
        }
        parent::tearDown();
    }

    public function testThePageKeepsTheNodeSubscriptionAndDropsTheCatalog(): void
    {
        self::assertSame(HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS, AbstractHilosDaemonEnvOrphansPage::PAGE);
        self::assertSame(PageReach::ROUTE, AbstractHilosDaemonEnvOrphansPage::REACH);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosDaemonEnvOrphansPage::ACCESS_LEVEL);
        self::assertSame(HilosAgentType::HILOS_DAEMON_NODE, AbstractHilosDaemonEnvOrphansPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame([
            PageAgentIndexKey::SOURCE => PageAgentIndexSource::NODE_PARAM,
            PageAgentIndexKey::PARAM => HilosPageRouteParams::HILOS_DAEMON_NODE_ID,
            PageAgentIndexKey::FALLBACK_AGENT_TYPE => HilosAgentType::HILOS_DAEMON,
        ], AbstractHilosDaemonEnvOrphansPage::SUBSCRIPTION_AGENT_INDEX);
        self::assertSame([HilosClusterNode::RT_COLLECTION], AbstractHilosDaemonEnvOrphansPage::READS_RT);
        self::assertSame(
            [BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_ENV_ORPHANS],
            AbstractHilosDaemonEnvOrphansPage::BROWSER,
        );
        self::assertEquals([
            AbstractHilosDaemonEnvOrphansPage::NODE => WireField::notPersonal(),
            AbstractHilosDaemonEnvOrphansPage::NODE_SILENT => WireField::notPersonal(),
        ], (new DaemonEnvOrphansPageTestPage(new DaemonCollectorAgent()))->fields());
    }

    public function testTheOwningNodeOmitsTheCatalogOnStandaloneAndInACluster(): void
    {
        Hilos::$rt->hilosClusterNodes->actions->publish('node-A', NodeRole::Slave->value, [], null, true, 42.0);
        $page = new DaemonEnvOrphansPageTestPage($this->nodeAgent('node-A'));
        $params = new PageRouteParams(['nodeId' => 'node-A']);

        $admin = $page->payload('ak', $params);
        $this->assertSame(['node', 'nodeSilent'], array_keys($admin->data));
        $this->assertFalse($admin->data[AbstractHilosDaemonEnvOrphansPage::NODE_SILENT]);
        $this->assertSame(['clustered' => false, 'state' => null, 'silentSince' => null, 'role' => 'slave'],
            $admin->data[AbstractHilosDaemonEnvOrphansPage::NODE]);
        $this->assertSame([], $admin->windows);
        $this->assertStringNotContainsString('password', (string)json_encode($admin->data));

        Hilos::$browser = new DaemonEnvOrphansPageTestBrowser(true);
        $viewer = $page->payload('ak', $params)->data;
        $this->assertSame($admin->data[AbstractHilosDaemonEnvOrphansPage::NODE], $viewer[AbstractHilosDaemonEnvOrphansPage::NODE]);
        $this->assertSame(['node', 'nodeSilent'], array_keys($viewer));
        $this->assertStringNotContainsString('password', (string)json_encode($viewer));

        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $clustered = $page->payload('ak', $params)->data;
        $this->assertSame(['clustered' => true, 'state' => null, 'silentSince' => null, 'role' => 'slave'],
            $clustered[AbstractHilosDaemonEnvOrphansPage::NODE]);
        $this->assertFalse($clustered[AbstractHilosDaemonEnvOrphansPage::NODE_SILENT]);
        $this->assertSame(['node', 'nodeSilent'], array_keys($clustered));
    }

    public function testAForeignReadingStaysSilentWithoutTheCatalog(): void
    {
        Hilos::$rt->hilosClusterNodes->actions->publish('node-A', NodeRole::Slave->value, [], null, true, 42.0);
        $page = new DaemonEnvOrphansPageTestPage($this->nodeAgent('node-B'));
        $data = $page->payload('ak', new PageRouteParams(['nodeId' => 'node-A']))->data;

        $this->assertTrue($data[AbstractHilosDaemonEnvOrphansPage::NODE_SILENT]);
        $this->assertSame(['clustered' => false, 'state' => 'silent', 'silentSince' => null, 'role' => 'slave'],
            $data[AbstractHilosDaemonEnvOrphansPage::NODE]);
        $this->assertSame(['node', 'nodeSilent'], array_keys($data));
        $this->assertStringNotContainsString('password', (string)json_encode($data));
    }

    public function testFallbackAnswersSilentWithoutTheCatalogOrSectionMirrorLease(): void
    {
        $page = new DaemonEnvOrphansPageTestPage(new DaemonCollectorAgent());
        $params = new PageRouteParams(['nodeId' => 'node-A']);
        $payload = $page->payload('ak', $params);

        $this->assertTrue($payload->data[AbstractHilosDaemonEnvOrphansPage::NODE_SILENT]);
        $this->assertSame(['clustered' => false, 'state' => 'silent', 'silentSince' => null, 'role' => null],
            $payload->data[AbstractHilosDaemonEnvOrphansPage::NODE]);
        $this->assertSame(['node', 'nodeSilent'], array_keys($payload->data));
        $this->assertSame([], $payload->windows);
        $page->afterResponse('ak', $params);
        $this->assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }

    public function testClusterFallbackUsesDeclaredRoleWithoutTheCatalog(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        Hilos::$rt->hilosClusterNodes->actions->publish('node-A', NodeRole::Master->value, [], null, false, 42.0);
        $page = new DaemonEnvOrphansPageTestPage(new DaemonCollectorAgent());
        $data = $page->payload('ak', new PageRouteParams(['nodeId' => 'node-A']))->data;

        $this->assertSame(['clustered' => true, 'state' => 'silent', 'silentSince' => null, 'role' => 'master'],
            $data[AbstractHilosDaemonEnvOrphansPage::NODE]);
        $this->assertTrue($data[AbstractHilosDaemonEnvOrphansPage::NODE_SILENT]);
        $this->assertSame(['node', 'nodeSilent'], array_keys($data));
        $this->assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }

    private function nodeAgent(string $nodeId): DaemonNodeAgent
    {
        $agent = new DaemonNodeAgent();
        new ReflectionProperty(DaemonNodeAgent::class, 'environment')->setValue($agent, new NodeEnvironmentReading(
            $nodeId,
            42,
            [new NodeEnvironmentKey('SECRET', 'string', true, false, true, false, false,
                new EnvResolution(EnvSource::ENV_FILE, 'password'), null)],
            [],
        ));

        return $agent;
    }
}

final class DaemonEnvOrphansPageTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

final class DaemonEnvOrphansPageTestPage extends AbstractHilosDaemonEnvOrphansPage
{
    public function payload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return $this->buildPagePayload($acceptKey, $params);
    }

    /** @return array<string, WireField> Value visibility declarations */
    public function fields(): array
    {
        return $this->dataFields();
    }

    public function afterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribeAfterResponse($acceptKey, $params);
    }
}

final class DaemonEnvOrphansPageTestBrowser extends BrowserContext
{
    public function __construct(private readonly bool $viewer)
    {
        parent::__construct();
    }

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}
