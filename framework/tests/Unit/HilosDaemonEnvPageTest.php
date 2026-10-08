<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\ClusterContext;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\NodeEnvironmentKey;
use Hilos\DaemonSection\NodeEnvironmentReading;
use Hilos\Environment\EnvResolution;
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvPage;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The environment page answers from its node owner and masks before the frame leaves. */
final class HilosDaemonEnvPageTest extends TestCase
{
    private ?BrowserContext $previousBrowser = null;

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousBrowser = Hilos::$browser;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$browser = new DaemonEnvPageTestBrowser(false);
        Hilos::$cluster = null;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Hilos::$browser = $this->previousBrowser;
        Hilos::$cluster = $this->previousCluster;
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            ClusterDaemonPictureMirror::removeViewer($acceptKey);
        }
        parent::tearDown();
    }

    public function testTheOwningNodeAnswersTheWholePageAndHidesClosedValuesForAViewer(): void
    {
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
        $this->assertSame(['kind' => 'secret', 'length' => 8],
            $admin[AbstractHilosDaemonEnvPage::ENVIRONMENT][NodeEnvironmentReading::KEYS][0][NodeEnvironmentReading::VALUE]);
        $this->assertEquals([
            AbstractHilosDaemonEnvPage::ENVIRONMENT => WireField::notPersonal(),
            AbstractHilosDaemonEnvPage::NODE_SILENT => WireField::notPersonal(),
        ], $page->fields());

        Hilos::$browser = new DaemonEnvPageTestBrowser(true);
        $viewer = $page->payload('ak', $params)->data;
        $this->assertSame(HiddenValue::mark(),
            $viewer[AbstractHilosDaemonEnvPage::ENVIRONMENT][NodeEnvironmentReading::KEYS][0][NodeEnvironmentReading::VALUE]);
        $this->assertStringNotContainsString('password', (string)json_encode($viewer));
        $this->assertStringNotContainsString('length', (string)json_encode($viewer));
    }

    public function testFallbackAnswersSilentWithoutValuesOrSectionMirrorLease(): void
    {
        $page = new DaemonEnvPageTestPage(new DaemonCollectorAgent());
        $params = new PageRouteParams(['nodeId' => 'node-A']);
        $data = $page->payload('ak', $params)->data;

        $this->assertNull($data[AbstractHilosDaemonEnvPage::ENVIRONMENT]);
        $this->assertTrue($data[AbstractHilosDaemonEnvPage::NODE_SILENT]);
        $page->afterResponse('ak', $params);
        $this->assertSame([], ClusterDaemonPictureMirror::viewerKeys());
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
