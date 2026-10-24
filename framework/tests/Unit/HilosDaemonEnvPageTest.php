<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\NodeEnvironmentKey;
use Hilos\DaemonSection\NodeEnvironmentReading;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvResolution;
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvPage;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The environment page answers from its node owner and masks before the frame leaves. */
final class HilosDaemonEnvPageTest extends TestCase
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
        Hilos::$browser = new DaemonEnvPageTestBrowser(false);
        Hilos::$cluster = null;
        Hilos::$env = new EnvAccessor();
        Hilos::$rt = new DaemonEnvPageTestRtContext();
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

    public function testTheOwningNodeAnswersTheWholePageAndHidesClosedValuesForAViewer(): void
    {
        Hilos::$rt->hilosClusterNodes->actions->publish('node-A', NodeRole::Slave->value, [], null, true, 42.0);
        $agent = new DaemonNodeAgent();
        new ReflectionProperty(DaemonNodeAgent::class, 'environment')->setValue($agent, new NodeEnvironmentReading(
            'node-A',
            42,
            [new NodeEnvironmentKey('SECRET', 'string', true, false, true, false, false,
                new EnvResolution(EnvSource::ENV_FILE, 'password'), null)],
            [],
        ));
        $page = new DaemonEnvPageTestPage($agent);
        $params = new PageRouteParams(['nodeId' => 'node-A']);

        $admin = $page->payload('ak', $params)->data;
        $this->assertFalse($admin[AbstractHilosDaemonEnvPage::NODE_SILENT]);
        $this->assertSame(['clustered' => false, 'state' => null, 'silentSince' => null, 'role' => 'slave'],
            $admin[AbstractHilosDaemonEnvPage::NODE]);
        $this->assertSame(['kind' => 'secret', 'length' => 8],
            $admin[AbstractHilosDaemonEnvPage::ENVIRONMENT][NodeEnvironmentReading::KEYS][0][NodeEnvironmentReading::VALUE]);
        $this->assertEquals([
            AbstractHilosDaemonEnvPage::NODE => WireField::notPersonal(),
            AbstractHilosDaemonEnvPage::ENVIRONMENT => WireField::notPersonal(),
            AbstractHilosDaemonEnvPage::NODE_SILENT => WireField::notPersonal(),
        ], $page->fields());

        Hilos::$browser = new DaemonEnvPageTestBrowser(true);
        $viewer = $page->payload('ak', $params)->data;
        $this->assertSame($admin[AbstractHilosDaemonEnvPage::NODE], $viewer[AbstractHilosDaemonEnvPage::NODE]);
        $this->assertSame(HiddenValue::mark(),
            $viewer[AbstractHilosDaemonEnvPage::ENVIRONMENT][NodeEnvironmentReading::KEYS][0][NodeEnvironmentReading::VALUE]);
        $this->assertStringNotContainsString('password', (string)json_encode($viewer));
        $this->assertStringNotContainsString('length', (string)json_encode($viewer));

        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $clustered = $page->payload('ak', $params)->data;
        $this->assertSame(['clustered' => true, 'state' => null, 'silentSince' => null, 'role' => 'slave'],
            $clustered[AbstractHilosDaemonEnvPage::NODE]);
        $this->assertTrue($clustered[AbstractHilosDaemonEnvPage::ENVIRONMENT][NodeEnvironmentReading::CLUSTER]);
    }

    public function testFallbackAnswersSilentWithoutValuesOrSectionMirrorLease(): void
    {
        $page = new DaemonEnvPageTestPage(new DaemonCollectorAgent());
        $params = new PageRouteParams(['nodeId' => 'node-A']);
        $data = $page->payload('ak', $params)->data;

        $this->assertNull($data[AbstractHilosDaemonEnvPage::ENVIRONMENT]);
        $this->assertTrue($data[AbstractHilosDaemonEnvPage::NODE_SILENT]);
        $this->assertSame(['clustered' => false, 'state' => 'silent', 'silentSince' => null, 'role' => null],
            $data[AbstractHilosDaemonEnvPage::NODE]);
        $page->afterResponse('ak', $params);
        $this->assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }

    public function testClusterFallbackUsesDeclaredRoleWithoutInventingAnElectionState(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        Hilos::$rt->hilosClusterNodes->actions->publish('node-A', NodeRole::Master->value, [], null, false, 42.0);
        $page = new DaemonEnvPageTestPage(new DaemonCollectorAgent());
        $data = $page->payload('ak', new PageRouteParams(['nodeId' => 'node-A']))->data;
        $this->assertSame(['clustered' => true, 'state' => 'silent', 'silentSince' => null, 'role' => 'master'],
            $data[AbstractHilosDaemonEnvPage::NODE]);
        $this->assertNull($data[AbstractHilosDaemonEnvPage::ENVIRONMENT]);
        $this->assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }
}

final class DaemonEnvPageTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

final class DaemonEnvPageTestPage extends AbstractHilosDaemonEnvPage
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

final class DaemonEnvPageTestBrowser extends BrowserContext
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
