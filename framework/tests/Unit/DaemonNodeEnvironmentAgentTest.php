<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\LocalNodeEnvironment;
use Hilos\DaemonSection\NodeEnvironmentReading;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Fs\Watch\FsRescanSchedule;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvOrphansPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvPage;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** A watched dotenv change re-answers only live subscribers of this node. */
final class DaemonNodeEnvironmentAgentTest extends TestCase
{
    private const string KEY = 'HILOS_NODE_AGENT_TEST';

    private ?EnvAccessor $previousEnv = null;
    private ?ClusterContext $previousCluster = null;
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private ?RtContext $previousRt = null;
    private ?DaemonNodeAgent $agent = null;
    private ?string $root = null;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        Hilos::$cluster = null;
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonNodeEnvironmentTestBrowser();
        Hilos::$rt = new DaemonNodeEnvironmentRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
        DaemonNodeEnvironmentCatalog::$catalog = [
            self::KEY => [EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING],
        ];
        $this->root = sys_get_temp_dir() . '/hilos-node-agent-env-' . uniqid();
        mkdir($this->root);
        file_put_contents($this->root . '/.env', self::KEY . "=first\n");
        Hilos::$env = new EnvAccessor(DaemonNodeEnvironmentCatalog::class);
        Hilos::$env->init($this->root);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->agent?->onStop();
        RtTruthSourceRegistry::unregisterDaemon(HilosClusterNode::RT_COLLECTION);
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        if ($this->root !== null) {
            foreach (array_diff(scandir($this->root) ?: [], ['.', '..']) as $name) {
                unlink($this->root . '/' . $name);
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testRescanReanswersLiveViewersAndPreservesTheProcessValue(): void
    {
        $this->agent = new DaemonNodeAgent();
        $this->agent->onStart();
        $firstFrame = Hilos::$sr->getNextQueuedSignal()?->data?->data;
        $this->assertInstanceOf(DaemonNodePictureSignalData::class, $firstFrame);
        $this->assertSame(1, $firstFrame->picture->environment?->catalogKeys);
        $this->assertSame($this->agent->environmentReading(), LocalNodeEnvironment::reading());

        Hilos::$rt->hilosClusterNodes->actions->publish('standalone', NodeRole::Slave->value, [], null, true, 42.0);
        Hilos::$browser->page = new DaemonNodeEnvironmentTestPage($this->agent);
        $initial = Hilos::$browser->page->payload('ak-live')->data;
        $this->assertSame(['clustered' => false, 'state' => null, 'silentSince' => null, 'role' => 'slave'], $initial['node']);

        Hilos::$sr->subscribeToPage(
            HilosPageConstants::HILOS_DAEMON_ENV,
            new WebSocketPageSubscribeSignalDTO('ak-live', HilosPageConstants::HILOS_DAEMON_ENV,
                [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 'standalone']),
        );
        Hilos::$rt->connectionsSource()?->add(DaemonNodeEnvironmentConnection::create('ak-live', null));

        file_put_contents($this->root . '/.env', self::KEY . "=second\n");
        $this->forceWatchWindowDue();
        $this->agent->onTick();

        $reading = $this->agent->environmentReading();
        $this->assertSame('first', $reading?->keys[0]->process->value);
        $this->assertSame('second', $reading?->keys[0]->disk?->value);
        $this->assertSame(1, $reading?->summary()->drifted);
        $this->assertSame([['ak-live', HilosPageConstants::HILOS_DAEMON_ENV]], Hilos::$browser->resent);
        $this->assertSame($reading, Hilos::$browser->readingAtResend[0]);
        $this->assertSame($initial['node'], Hilos::$browser->payloads[0]->data['node']);
        $this->assertFalse(Hilos::$browser->payloads[0]->data['nodeSilent']);
        $this->assertSame('second', Hilos::$browser->payloads[0]->data['environment']['keys'][0]['disk']['value']['text']);

        $this->forceWatchWindowDue();
        $this->agent->onTick();
        $this->assertCount(1, Hilos::$browser->resent);

        Hilos::$browser->viewer = true;
        file_put_contents($this->root . '/.env', self::KEY . "=third\n");
        $this->forceWatchWindowDue();
        $this->agent->onTick();
        $this->assertCount(2, Hilos::$browser->resent);
        $this->assertSame($initial['node'], Hilos::$browser->payloads[1]->data['node']);
        $this->assertSame(HiddenValue::mark(), Hilos::$browser->payloads[1]->data['environment']['keys'][0]['value']);
        $this->assertNull(Hilos::$browser->payloads[1]->data['environment']['keys'][0]['disk']);

        Hilos::$rt->connectionsSource()?->remove('ak-live');
        file_put_contents($this->root . '/.env', self::KEY . "=fourth\n");
        $this->forceWatchWindowDue();
        $this->agent->onTick();
        $this->assertCount(2, Hilos::$browser->resent);
    }

    public function testRescanReanswersBothEnvironmentPagesOfThisNodeOnly(): void
    {
        $this->agent = new DaemonNodeAgent();
        $this->agent->onStart();
        Hilos::$sr->getNextQueuedSignal();
        Hilos::$rt->hilosClusterNodes->actions->publish('standalone', NodeRole::Slave->value, [], null, true, 42.0);
        Hilos::$rt->hilosClusterNodes->actions->publish('other', NodeRole::Slave->value, [], null, true, 42.0);
        Hilos::$browser->page = new DaemonNodeEnvironmentTestPage($this->agent);
        Hilos::$browser->orphansPage = new DaemonNodeEnvironmentOrphansTestPage($this->agent);
        foreach ([
            [HilosPageConstants::HILOS_DAEMON_ENV, 'ak-env', 'standalone'],
            [HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS, 'ak-orphans', 'standalone'],
            [HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS, 'ak-other', 'other'],
            [HilosPageConstants::HILOS_DAEMON_ENV, 'ak-closed', 'standalone'],
        ] as [$page, $acceptKey, $nodeId]) {
            Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO(
                $acceptKey, $page, [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $nodeId],
            ));
        }
        foreach (['ak-env', 'ak-orphans', 'ak-other'] as $acceptKey) {
            Hilos::$rt->connectionsSource()?->add(DaemonNodeEnvironmentConnection::create($acceptKey, null));
        }

        file_put_contents($this->root . '/.env', self::KEY . "=second\n");
        $this->forceWatchWindowDue();
        $this->agent->onTick();

        $reading = $this->agent->environmentReading();
        $this->assertEqualsCanonicalizing([
            ['ak-env', HilosPageConstants::HILOS_DAEMON_ENV],
            ['ak-orphans', HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS],
        ], Hilos::$browser->resent);
        $this->assertSame([$reading, $reading], Hilos::$browser->readingAtResend);
        $this->assertFalse(Hilos::$browser->payloads[0]->data['nodeSilent']);
        $this->assertSame('second', Hilos::$browser->payloads[0]->data['environment']['keys'][0]['disk']['value']['text']);
        $this->assertSame(['node', 'nodeSilent'], array_keys(Hilos::$browser->payloads[1]->data));
        $this->assertFalse(Hilos::$browser->payloads[1]->data['nodeSilent']);
        $this->assertStringNotContainsString('second', (string)json_encode(Hilos::$browser->payloads[1]->data));

        $this->forceWatchWindowDue();
        $this->agent->onTick();
        $this->assertCount(2, Hilos::$browser->resent);

        Hilos::$browser->viewer = true;
        file_put_contents($this->root . '/.env', self::KEY . "=third\n");
        $this->forceWatchWindowDue();
        $this->agent->onTick();
        $this->assertCount(4, Hilos::$browser->resent);
        $this->assertSame(HiddenValue::mark(), Hilos::$browser->payloads[2]->data['environment']['keys'][0]['value']);
        $this->assertSame(['node', 'nodeSilent'], array_keys(Hilos::$browser->payloads[3]->data));
        $this->assertStringNotContainsString('third', (string)json_encode(Hilos::$browser->payloads[3]->data));
    }

    public function testHolderForgetsOnlyItsNodeOnStop(): void
    {
        $this->agent = new DaemonNodeAgent();
        $this->agent->onStart();
        $reading = $this->agent->environmentReading();
        $this->assertSame($reading, LocalNodeEnvironment::reading());
        LocalNodeEnvironment::forget('another-node');
        $this->assertSame($reading, LocalNodeEnvironment::reading());

        $this->agent->onStop();
        $this->agent = null;
        $this->assertNull(LocalNodeEnvironment::reading());
    }

    private function forceWatchWindowDue(): void
    {
        $schedule = new FsRescanSchedule(microtime(true) - 2.0);
        $schedule->noteChanges(microtime(true) - 2.0);
        new ReflectionProperty(DaemonNodeAgent::class, 'directoryRescanSchedule')
            ->setValue($this->agent, $schedule);
    }
}

final class DaemonNodeEnvironmentTestBrowser extends BrowserContext
{
    /** @var list<array{string, string}> Page and connection pairs re-answered */
    public array $resent = [];

    /** @var list<?NodeEnvironmentReading> Reading held at the instant of re-answer */
    public array $readingAtResend = [];

    /** @var list<PagePayload> Whole environment answers built on live resends */
    public array $payloads = [];

    public ?DaemonNodeEnvironmentTestPage $page = null;
    public ?DaemonNodeEnvironmentOrphansTestPage $orphansPage = null;
    public bool $viewer = false;

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    public function resendPageWhole(string $page, string $acceptKey): void
    {
        $this->resent[] = [$acceptKey, $page];
        $this->readingAtResend[] = LocalNodeEnvironment::reading();
        $this->payloads[] = $page === HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS
            ? $this->orphansPage->payload($acceptKey)
            : $this->page->payload($acceptKey);
    }
}

final class DaemonNodeEnvironmentTestPage extends AbstractHilosDaemonEnvPage
{
    public function payload(string $acceptKey): PagePayload
    {
        return $this->buildPagePayload($acceptKey, new PageRouteParams(['nodeId' => 'standalone']));
    }
}

final class DaemonNodeEnvironmentOrphansTestPage extends AbstractHilosDaemonEnvOrphansPage
{
    public function payload(string $acceptKey): PagePayload
    {
        return $this->buildPagePayload($acceptKey, new PageRouteParams(['nodeId' => 'standalone']));
    }
}

final class DaemonNodeEnvironmentCatalog implements CatalogProviderInterface
{
    /** @var array<string, array<string, mixed>> Test environment catalog */
    public static array $catalog = [];

    /** @return array<string, array<string, mixed>> Test catalog */
    public static function getCatalog(): array
    {
        return self::$catalog;
    }
}

final class DaemonNodeEnvironmentConnection extends HilosConnection
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

/** @extends HilosConnections<DaemonNodeEnvironmentConnection> */
final class DaemonNodeEnvironmentConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonNodeEnvironmentConnection::class;
}

final class DaemonNodeEnvironmentRtContext extends RtContext
{
    public const string CONNECTIONS = 'daemonNodeEnvironmentConnections';

    public function configure(): void
    {
        $this->_stateCollections[self::CONNECTIONS] = DaemonNodeEnvironmentConnections::init();
    }
}
